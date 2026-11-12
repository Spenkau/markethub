<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceProduct;
use Illuminate\Support\Facades\DB;

class MarketplaceImportService
{
    /** Accept normalized provider data. Mapping is never part of a source update. */
    public function upsert(MarketplaceAccount $account, array $parent, array $variant, array $raw, ?int $existingId = null, bool $backfill = false): array
    {
        $parent = array_intersect_key($parent, array_flip(['external_id', 'title', 'description', 'status', 'images', 'source_data', 'source_created_at', 'source_updated_at', 'source_synced_at']));

        return DB::transaction(function () use ($account, $parent, $variant, $raw, $existingId, $backfill) {
            MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);
            $product = MarketplaceProduct::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('external_id', $parent['external_id'])->lockForUpdate()->first();
            if (! $product) {
                $product = new MarketplaceProduct;
                $product->forceFill(['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, ...$parent])->save();
            } elseif ($this->isNewer($parent, $product)) {
                $product->forceFill($parent);
                if ($product->isDirty()) {
                    $product->save();
                }
            }
            $query = DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id);
            $existing = ($existingId ? $query->where('id', $existingId) : $query->where('external_id', $variant['external_id']))->lockForUpdate()->first();
            abort_if($existingId && (! $existing || $existing->external_id !== $variant['external_id']), 422, 'Внешняя идентичность варианта не совпадает.');
            // Explicit whitelist: product_id cannot be supplied or written back by an importer.
            $fields = array_intersect_key($variant, array_flip(['external_id', 'external_sku', 'variant_title', 'barcode', 'price_minor', 'currency', 'status', 'source_status', 'source_created_at', 'source_updated_at', 'source_synced_at', 'source_seen_run']));
            $fields += ['marketplace_product_id' => $product->id, 'origin' => 'imported', 'source_data' => json_encode($raw, JSON_THROW_ON_ERROR)];
            if ($existing) {
                // Avoid touching timestamps during an identical backfill.
                $changed = false;
                foreach ($fields as $key => $value) {
                    if ($key === 'source_data') {
                        $different = json_decode($existing->{$key}, true) != $raw;
                    } elseif (str_ends_with($key, '_at') && $value && $existing->{$key}) {
                        $different = strtotime($existing->{$key}) !== strtotime($value);
                    } else {
                        $different = $existing->{$key} != $value;
                    }
                    if ($different) {
                        $changed = true;
                        break;
                    }
                }
                if ($changed) {
                    DB::table('marketplace_listings')->where('id', $existing->id)->update($backfill ? $fields : [...$fields, 'updated_at' => now()->toIso8601String()]);
                }

                return ['id' => $existing->id, 'created' => false];
            }
            $id = DB::table('marketplace_listings')->insertGetId(['organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'product_id' => null, 'stock_quantity' => null, ...$fields, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);

            return ['id' => $id, 'created' => true];
        });
    }

    private function isNewer(array $incoming, MarketplaceProduct $existing): bool
    {
        $new = [strtotime($incoming['source_updated_at'] ?? '') ?: 0, strtotime($incoming['source_synced_at'] ?? '') ?: 0];
        $old = [$existing->source_updated_at?->timestamp ?? 0, $existing->source_synced_at?->timestamp ?? 0];

        return $new >= $old;
    }
}
