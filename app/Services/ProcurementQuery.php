<?php

namespace App\Services;

use App\Http\Requests\Api\V1\ProcurementIndexRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class ProcurementQuery
{
    public function suppliers(ProcurementIndexRequest $request): Builder
    {
        $query = Supplier::query()->withCount('purchaseOrders');
        if ($request->filled('search')) {
            $search = '%'.$request->validated('search').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $search)->orWhere('contact_name', 'ilike', $search)->orWhere('phone', 'ilike', $search)->orWhere('email', 'ilike', $search)->orWhere('inn', 'ilike', $search));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query->orderBy('name')->orderBy('id');
    }

    public function orders(ProcurementIndexRequest $request): Builder
    {
        $query = PurchaseOrder::query()->with(['supplier', 'warehouse'])->withCount('receipts')->withSum('items as ordered_quantity', 'quantity')->withSum('items as received_quantity_total', 'received_quantity');
        foreach (['status', 'supplier_id', 'warehouse_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $search = '%'.$request->validated('search').'%';
            $query->where(fn ($q) => $q->whereRaw('CAST(purchase_orders.id AS text) ILIKE ?', [$search])->orWhereHas('supplier', fn ($q) => $q->where('name', 'ilike', $search))->orWhereHas('items.product', fn ($q) => $q->where('name', 'ilike', $search)->orWhere('sku', 'ilike', $search)));
        }

        return $query->orderByDesc('id');
    }

    public function receipts(ProcurementIndexRequest $request): Builder
    {
        $query = GoodsReceipt::query()->with(['purchaseOrder.supplier', 'purchaseOrder.warehouse', 'creator', 'confirmer'])->withSum('items as total_quantity', 'quantity');
        foreach (['status', 'purchase_order_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        foreach (['supplier_id', 'warehouse_id'] as $field) {
            if ($request->has($field)) {
                $query->whereHas('purchaseOrder', fn ($q) => $q->where($field, $request->validated($field)));
            }
        }
        if ($request->filled('search')) {
            $search = '%'.$request->validated('search').'%';
            $query->where(fn ($q) => $q->whereRaw('CAST(goods_receipts.id AS text) ILIKE ?', [$search])->orWhereHas('purchaseOrder.supplier', fn ($q) => $q->where('name', 'ilike', $search))->orWhereHas('items.product', fn ($q) => $q->where('name', 'ilike', $search)->orWhere('sku', 'ilike', $search)));
        }

        return $query->orderByDesc('id');
    }
}
