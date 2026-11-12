<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcurementService
{
    public function __construct(private StockOperationService $stocks) {}

    private function actor(User $actor): int
    {
        abort_unless(auth()->id() === $actor->id && OrganizationContext::id($actor) !== null, 403);

        return OrganizationContext::id($actor);
    }

    private function lockRequest(int $org, string $kind, string $id): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', [$org.':'.$kind.':'.$id]);
    }

    private function normalize(array $data, string $kind): array
    {
        $items = array_map(function ($item) use ($kind) {
            $row = [$kind === 'order' ? 'product_id' : 'purchase_order_item_id' => (int) $item[$kind === 'order' ? 'product_id' : 'purchase_order_item_id'], 'quantity' => (int) $item['quantity']];
            if ($kind === 'order') {
                [$whole, $fraction] = array_pad(explode('.', $item['unit_price']), 2, '');
                $row['unit_price'] = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
            }

            return $row;
        }, $data['items']);
        usort($items, fn ($a, $b) => array_values($a)[0] <=> array_values($b)[0]);

        return $kind === 'order'
            ? ['supplier_id' => (int) $data['supplier_id'], 'warehouse_id' => (int) $data['warehouse_id'], 'currency' => $data['currency'], 'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null, 'items' => $items]
            : ['purchase_order_id' => (int) $data['purchase_order_id'], 'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null, 'items' => $items];
    }

    private function checkDestination(array $data): void
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        Gate::authorize('view', $supplier);
        Gate::authorize('view', $warehouse);
        if (! $supplier->is_active || ! $warehouse->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Выберите активного поставщика и активный склад.']);
        }
    }

    private function saveOrderLines(PurchaseOrder $order, array $items): void
    {
        PurchaseOrderItem::where('purchase_order_id', $order->id)->delete();
        foreach ($items as $item) {
            PurchaseOrderItem::create(['organization_id' => $order->organization_id, 'purchase_order_id' => $order->id, ...$item]);
        }
        $total = PurchaseOrderItem::where('purchase_order_id', $order->id)->selectRaw('COALESCE(SUM(line_total), 0) AS amount')->first()->amount;
        $order->update(['total_amount' => (string) $total]);
    }

    public function createOrder(array $input, User $actor): array
    {
        $org = $this->actor($actor);
        Gate::authorize('create', PurchaseOrder::class);
        $data = $this->normalize($input, 'order');
        $key = strtolower($input['request_id']);
        $hash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($org, $actor, $data, $key, $hash) {
            $this->lockRequest($org, 'purchase', $key);
            $existing = PurchaseOrder::where('request_id', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Ключ запроса уже использован для другой закупки.');

                return ['record' => $this->orderDetails($existing), 'replayed' => true];
            }
            $this->checkDestination($data);
            $order = PurchaseOrder::create(['organization_id' => $org, 'created_by' => $actor->id, 'request_id' => $key, 'request_hash' => $hash, 'status' => 'draft', ...array_diff_key($data, ['items' => true])]);
            $this->saveOrderLines($order, $data['items']);

            return ['record' => $this->orderDetails($order), 'replayed' => false];
        }, 3);
    }

    public function updateOrder(PurchaseOrder $purchaseOrder, array $input, User $actor): PurchaseOrder
    {
        $this->actor($actor);
        Gate::authorize('update', $purchaseOrder);
        $data = $this->normalize($input, 'order');

        return DB::transaction(function () use ($purchaseOrder, $data) {
            $order = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'draft', 409, 'Редактировать можно только черновик закупки.');
            $this->checkDestination($data);
            $order->update(array_diff_key($data, ['items' => true]));
            $this->saveOrderLines($order, $data['items']);

            return $this->orderDetails($order);
        }, 3);
    }

    public function transition(PurchaseOrder $purchaseOrder, string $action, User $actor): PurchaseOrder
    {
        $this->actor($actor);
        Gate::authorize('update', $purchaseOrder);

        return DB::transaction(function () use ($purchaseOrder, $action) {
            $order = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($action === 'order') {
                if ($order->status === 'ordered') {
                    return $this->orderDetails($order);
                }
                abort_unless($order->status === 'draft', 409, 'Заказать можно только черновик.');
                $this->checkDestination($order->toArray());
                abort_unless($order->items()->exists(), 409, 'Добавьте товары в закупку.');
                $order->update(['status' => 'ordered']);
            } else {
                if ($order->status === 'cancelled') {
                    return $this->orderDetails($order);
                }
                abort_unless(in_array($order->status, ['draft', 'ordered', 'partially_received'], true), 409, 'Полученную закупку нельзя отменить.');
                // Lock order is always purchase -> receipt. Confirmed documents are never changed.
                GoodsReceipt::where('purchase_order_id', $order->id)->where('status', 'draft')->update(['status' => 'cancelled', 'updated_at' => now()]);
                $order->update(['status' => 'cancelled']);
            }

            return $this->orderDetails($order);
        }, 3);
    }

    private function saveReceiptLines(GoodsReceipt $receipt, PurchaseOrder $order, array $items): void
    {
        abort_unless(in_array($order->status, ['ordered', 'partially_received'], true), 409, 'Приёмка доступна только для заказанной или частично полученной закупки.');
        $orderItems = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $index => $item) {
            $line = $orderItems->get($item['purchase_order_item_id']);
            if (! $line || $item['quantity'] > $line->quantity - $line->received_quantity) {
                throw ValidationException::withMessages(['items.'.$index.'.quantity' => 'Строка не относится к закупке или количество превышает остаток к получению.']);
            }
        }
        GoodsReceiptItem::where('goods_receipt_id', $receipt->id)->delete();
        foreach ($items as $item) {
            $line = $orderItems[$item['purchase_order_item_id']];
            GoodsReceiptItem::create(['organization_id' => $order->organization_id, 'goods_receipt_id' => $receipt->id, 'purchase_order_id' => $order->id, 'product_id' => $line->product_id, 'operation_id' => (string) Str::uuid(), ...$item]);
        }
    }

    public function createReceipt(array $input, User $actor): array
    {
        $org = $this->actor($actor);
        Gate::authorize('create', GoodsReceipt::class);
        $data = $this->normalize($input, 'receipt');
        $key = strtolower($input['request_id']);
        $hash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($org, $actor, $data, $key, $hash) {
            $this->lockRequest($org, 'goods-receipt', $key);
            $existing = GoodsReceipt::where('request_id', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Ключ запроса уже использован для другой приёмки.');

                return ['record' => $this->receiptDetails($existing), 'replayed' => true];
            }
            $order = PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $order);
            $receipt = GoodsReceipt::create(['organization_id' => $org, 'purchase_order_id' => $order->id, 'created_by' => $actor->id, 'request_id' => $key, 'request_hash' => $hash, 'status' => 'draft', 'comment' => $data['comment']]);
            $this->saveReceiptLines($receipt, $order, $data['items']);

            return ['record' => $this->receiptDetails($receipt), 'replayed' => false];
        }, 3);
    }

    public function updateReceipt(GoodsReceipt $goodsReceipt, array $input, User $actor): GoodsReceipt
    {
        $this->actor($actor);
        Gate::authorize('update', $goodsReceipt);
        $data = $this->normalize([...$input, 'purchase_order_id' => $goodsReceipt->purchase_order_id], 'receipt');

        return DB::transaction(function () use ($goodsReceipt, $data) {
            $order = PurchaseOrder::whereKey($goodsReceipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            $receipt = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->firstOrFail();
            abort_unless($receipt->status === 'draft', 409, 'Приёмка уже проведена или отменена.');
            $this->saveReceiptLines($receipt, $order, $data['items']);
            $receipt->update(['comment' => $data['comment']]);

            return $this->receiptDetails($receipt);
        }, 3);
    }

    public function confirm(GoodsReceipt $goodsReceipt, User $actor): array
    {
        $this->actor($actor);
        Gate::authorize('update', $goodsReceipt);

        return DB::transaction(function () use ($goodsReceipt, $actor) {
            $order = PurchaseOrder::whereKey($goodsReceipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            $receipt = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'confirmed') {
                return ['record' => $this->receiptDetails($receipt), 'replayed' => true];
            }
            abort_unless($receipt->status === 'draft' && in_array($order->status, ['ordered', 'partially_received'], true), 409, 'Закупка или приёмка не допускает проведение.');
            Gate::authorize('update', $order);
            $orderItems = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = $receipt->items()->orderBy('product_id')->lockForUpdate()->get();
            abort_if($lines->isEmpty(), 409, 'Нет строк для приёмки.');
            foreach ($lines as $line) {
                $item = $orderItems[$line->purchase_order_item_id];
                if ($line->quantity > $item->quantity - $item->received_quantity) {
                    throw ValidationException::withMessages(['items' => 'Количество приёмки превышает оставшееся количество заказа. Обновите черновик приёмки.']);
                }
                $operation = $this->stocks->execute([
                    'operation_id' => $line->operation_id, 'type' => 'receipt', 'warehouse_id' => $order->warehouse_id,
                    'product_id' => $item->product_id, 'quantity' => $line->quantity,
                    'comment' => 'Приёмка #'.$receipt->id.' по закупке #'.$order->id,
                ], $actor);
                $line->update(['stock_movement_id' => $operation['movements']->first()->id]);
                $item->update(['received_quantity' => $item->received_quantity + $line->quantity]);
            }
            $receipt->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            $complete = ! $order->items()->whereColumn('received_quantity', '<', 'quantity')->exists();
            $order->update(['status' => $complete ? 'received' : 'partially_received']);

            return ['record' => $this->receiptDetails($receipt), 'replayed' => false];
        }, 3);
    }

    public function cancelReceipt(GoodsReceipt $goodsReceipt, User $actor): GoodsReceipt
    {
        $this->actor($actor);
        Gate::authorize('update', $goodsReceipt);

        return DB::transaction(function () use ($goodsReceipt) {
            PurchaseOrder::whereKey($goodsReceipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            $receipt = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->firstOrFail();
            abort_if($receipt->status === 'confirmed', 409, 'Проведённую приёмку нельзя отменить.');
            $receipt->update(['status' => 'cancelled']);

            return $this->receiptDetails($receipt);
        }, 3);
    }

    public function orderDetails(PurchaseOrder $order): PurchaseOrder
    {
        return $order->load(['supplier', 'warehouse', 'creator', 'items.product'])->loadCount('receipts')->loadSum('items as ordered_quantity', 'quantity')->loadSum('items as received_quantity_total', 'received_quantity');
    }

    public function receiptDetails(GoodsReceipt $receipt): GoodsReceipt
    {
        $receipt->load(['purchaseOrder.supplier', 'purchaseOrder.warehouse', 'creator', 'confirmer', 'items.product', 'items.orderItem']);
        $this->orderDetails($receipt->purchaseOrder);

        return $receipt;
    }
}
