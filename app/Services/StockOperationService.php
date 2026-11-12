<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\OrganizationContext;
use App\Support\StockOperationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StockOperationService
{
    public function execute(array $input, User $actor): array
    {
        Gate::forUser($actor)->authorize('create', StockMovement::class);
        abort_unless(auth()->id() === $actor->id, 403);
        $organizationId = OrganizationContext::id($actor);
        $data = Validator::make($input, StockOperationRules::rules($organizationId))->validate();
        $data = [
            'operation_id' => strtolower($data['operation_id']),
            'type' => $data['type'], 'warehouse_id' => (int) $data['warehouse_id'],
            'destination_warehouse_id' => isset($data['destination_warehouse_id']) ? (int) $data['destination_warehouse_id'] : null,
            'product_id' => (int) $data['product_id'], 'quantity' => (int) $data['quantity'],
            'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
        ];
        if ($data['type'] !== 'inventory' && $data['quantity'] === 0) {
            throw ValidationException::withMessages(['quantity' => 'Количество должно быть больше нуля.']);
        }
        $hash = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organizationId, $actor, $data, $hash): array {
            // Serializes retries even before any movement/stock row exists. Released on commit/rollback.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', [$organizationId.':'.$data['operation_id']]);
            $existing = StockMovement::query()->where('organization_id', $organizationId)->where('operation_id', $data['operation_id'])->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                abort_unless(hash_equals($existing->first()->request_hash, $hash), 409, 'Идентификатор операции уже использован с другими данными.');

                return ['movements' => $existing->load(['product', 'warehouse', 'user']), 'replayed' => true];
            }
            $warehouseIds = array_values(array_filter([$data['warehouse_id'], $data['destination_warehouse_id']]));
            sort($warehouseIds);
            // Identical lock order for opposite transfers. Warehouse locks also serialize first-row creation.
            $warehouses = Warehouse::query()->where('organization_id', $organizationId)->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get();
            if ($warehouses->count() !== count($warehouseIds) || $warehouses->contains(fn ($warehouse) => ! $warehouse->is_active)) {
                throw ValidationException::withMessages(['warehouse_id' => 'Выберите доступные активные склады.']);
            }
            $product = Product::query()->where('organization_id', $organizationId)->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $product);
            foreach ($warehouses as $warehouse) {
                Gate::forUser($actor)->authorize('update', $warehouse);
                WarehouseStock::query()->insertOrIgnore([
                    'organization_id' => $organizationId, 'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $stocks = WarehouseStock::query()->where('organization_id', $organizationId)->where('product_id', $product->id)->whereIn('warehouse_id', $warehouseIds)->orderBy('warehouse_id')->lockForUpdate()->get()->keyBy('warehouse_id');
            $source = $stocks[$data['warehouse_id']];
            $delta = match ($data['type']) {
                'receipt' => $data['quantity'],
                'write_off', 'transfer' => -$data['quantity'],
                'inventory' => $data['quantity'] - $source->quantity,
            };
            $changes = [[$source, $delta]];
            if ($data['type'] === 'transfer') {
                $changes[] = [$stocks[$data['destination_warehouse_id']], $data['quantity']];
            }
            $movements = collect();
            $occurredAt = now();
            foreach ($changes as [$stock, $change]) {
                $before = $stock->quantity;
                $after = $before + $change;
                if ($after < 0 || $after > 2147483647) {
                    throw ValidationException::withMessages(['quantity' => $after < 0 ? 'Недостаточно товара на складе.' : 'Превышен максимально допустимый остаток.']);
                }
                $stock->update(['quantity' => $after]);
                $movements->push(StockMovement::create([
                    'organization_id' => $organizationId, 'warehouse_id' => $stock->warehouse_id,
                    'product_id' => $product->id, 'user_id' => $actor->id, 'operation_id' => $data['operation_id'],
                    'request_hash' => $hash, 'type' => $data['type'], 'quantity_delta' => $change,
                    'quantity_before' => $before, 'quantity_after' => $after, 'comment' => $data['comment'], 'occurred_at' => $occurredAt,
                ]));
            }

            return ['movements' => $movements->each->load(['product', 'warehouse', 'user']), 'replayed' => false];
        }, 3);
    }
}
