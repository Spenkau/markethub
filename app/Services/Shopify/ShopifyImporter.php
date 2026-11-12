<?php

namespace App\Services\Shopify;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncRun;
use App\Services\Marketplaces\MarketplaceImportService;
use App\Services\Marketplaces\ProviderRegistry;
use App\Services\Marketplaces\SyncException;
use Illuminate\Support\Facades\DB;

class ShopifyImporter
{
    public function __construct(private ShopifyClient $client) {}

    public function check(MarketplaceAccount $account): array
    {
        $data = $this->client->query($account, 'query { shop { name currencyCode } currentAppInstallation { accessScopes { handle } } }');
        $scopes = array_column($data['currentAppInstallation']['accessScopes'], 'handle');
        $required = [...($account->sync_products ? ['read_products'] : []), ...($account->sync_inventory ? ['read_inventory', 'read_locations', 'read_products'] : [])];
        $missing = array_values(array_diff($required, $scopes));
        $account->forceFill(['api_status' => $missing ? 'missing_permissions' : 'connected', 'api_checked_at' => now(), 'shopify_scopes' => $scopes, 'shop_currency' => $data['shop']['currencyCode']])->save();

        return ['shop_name' => $data['shop']['name'], 'currency' => $data['shop']['currencyCode'], 'missing_scopes' => $missing];
    }

    public function execute(MarketplaceAccount $account, MarketplaceSyncRun $run): void
    {
        $started = now();
        $this->check($account);
        if ($run->target_listing_id) {
            $this->target($account, $run);

            return;
        }
        // Each section checks its own permissions; inventory may succeed when product sync fails.
        if ($run->sync_products) {
            try {
                $this->products($account, $run);
            } catch (ShopifyException $e) {
                if ($e->retryable) {
                    throw $e;
                }
                $run->recordError('products', $e->reason);
            }
        }
        if ($run->sync_inventory) {
            try {
                // Inventory-only imports discover identities without overwriting product metadata or creating local products.
                if (! $run->sync_products) {
                    $this->products($account, $run, true);
                }
                $this->inventory($account, $run);
            } catch (ShopifyException $e) {
                if ($e->retryable) {
                    throw $e;
                }
                $run->recordError('inventory', $e->reason);
            }
        }
        $run->checkpoint(['phase' => 'complete', 'complete' => true]);
    }

    private function target(MarketplaceAccount $account, MarketplaceSyncRun $run): void
    {
        $listing = DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('id', $run->target_listing_id)->first();
        if (! $listing) {
            throw new ShopifyException('invalid_data');
        }
        $data = $this->client->query($account, 'query TargetVariant($id: ID!) { productVariant(id: $id) { id title sku barcode price compareAtPrice createdAt updatedAt inventoryItem { id tracked } product { id title descriptionHtml handle vendor productType status createdAt updatedAt } } }', ['id' => $listing->external_id]);
        if (! array_key_exists('productVariant', $data)) {
            throw new ShopifyException('invalid_data');
        }
        $variant = $data['productVariant'];
        if ($variant === null) {
            $run->guardedWrite(function () use ($listing) {
                DB::table('marketplace_listings')->where('id', $listing->id)->update(['source_status' => 'deleted', 'status' => 'inactive', 'stock_quantity' => null, 'source_synced_at' => now()->toIso8601String()]);
                DB::table('shopify_inventory_levels')->where('marketplace_listing_id', $listing->id)->update(['is_current' => false]);
            });
            $run->recordResult('variant', $listing->external_id, 'updated');
        } else {
            $product = $variant['product'];
            unset($variant['product']);
            if ($run->sync_products) {
                $images = [];
                foreach ($this->pages($account, $run, 'query Images($id: ID!, $after: String) { product(id: $id) { images(first: 100, after: $after) { nodes { id url altText } pageInfo { hasNextPage endCursor } } } }', 'product.images', ['id' => $product['id']]) as $image) {
                    $images[] = $image;
                }
                $this->variant($account, $run, $product, $variant, $images, false);
                $run->recordResult('product', $product['id']);
            }
            if ($run->sync_inventory) {
                $this->inventory($account, $run);
            }
        }
        $run->checkpoint(['phase' => 'complete', 'complete' => true]);
    }

    private function products(MarketplaceAccount $account, MarketplaceSyncRun $run, bool $identityOnly = false): void
    {
        $query = <<<'GQL'
query Products($after: String, $filter: String) {
  products(first: 50, after: $after, query: $filter, sortKey: UPDATED_AT) {
    nodes { id title descriptionHtml handle vendor productType status createdAt updatedAt }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;
        $filter = ! $identityOnly && ! $run->full_reconcile && $account->last_products_synced_at
            ? 'updated_at:>='.$account->last_products_synced_at->subMinutes(5)->toIso8601String() : null;
        $initialErrors = $run->fresh()->errors_count;
        foreach ($this->pages($account, $run, $query, 'products', ['filter' => $filter]) as $product) {
            $images = [];
            if (! $identityOnly) {
                $imageQuery = 'query Images($id: ID!, $after: String) { product(id: $id) { images(first: 100, after: $after) { nodes { id url altText } pageInfo { hasNextPage endCursor } } } }';
                foreach ($this->pages($account, $run, $imageQuery, 'product.images', ['id' => $product['id']]) as $image) {
                    $images[] = $image;
                }
            }
            $variantsQuery = 'query Variants($id: ID!, $after: String) { product(id: $id) { variants(first: 100, after: $after) { nodes { id title sku barcode price compareAtPrice createdAt updatedAt inventoryItem { id tracked } } pageInfo { hasNextPage endCursor } } } }';
            $productErrors = $run->fresh()->errors_count;
            foreach ($this->pages($account, $run, $variantsQuery, 'product.variants', ['id' => $product['id']]) as $variant) {
                try {
                    $this->variant($account, $run, $product, $variant, $images, $identityOnly);
                } catch (\Throwable $e) {
                    if ($e instanceof SyncException && ($e->retryable || $e->reason === 'stale_attempt')) {
                        throw $e;
                    }
                    $this->recordError($run, (string) ($variant['id'] ?? 'variant'), 'Некорректные данные варианта; запись пропущена.');
                }
            }
            $run->recordResult('product', $product['id']);
            if (! $identityOnly && $run->fresh()->errors_count === $productErrors) {
                $this->markMissing($account, $run, $product['id']);
            }
        }
        if (! $identityOnly && $run->full_reconcile && $run->fresh()->errors_count === $initialErrors) {
            $this->markMissing($account, $run);
        }
    }

    private function variant(MarketplaceAccount $account, MarketplaceSyncRun $run, array $product, array $variant, array $images, bool $identityOnly): void
    {
        if ($identityOnly && DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('external_id', $variant['id'])->exists()) {
            $run->recordResult('variant', $variant['id'], 'skipped');

            return;
        }
        $raw = ['product' => [...$product, 'images' => $images], 'variant' => $variant];
        $normalize = app(ProviderRegistry::class)->get('shopify')->sourceNormalizer();
        $normalized = $normalize($account, $raw, now()->toIso8601String());
        $normalized['variant']['source_seen_run'] = $run->reconcile_token;
        $run->guardedWrite(function () use ($account, $run, $normalized, $raw, $variant) {
            $result = app(MarketplaceImportService::class)->upsert($account, $normalized['parent'], $normalized['variant'], $raw);
            $run->recordResult('variant', $variant['id'], $result['created'] ? 'created' : 'updated');
        });
    }

    private function markMissing(MarketplaceAccount $account, MarketplaceSyncRun $run, ?string $productId = null): void
    {
        $query = DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->whereNotNull('source_data')->where(fn ($q) => $q->whereNull('source_seen_run')->orWhere('source_seen_run', '<>', $run->reconcile_token));
        if ($productId) {
            $query->where('source_data->product->id', $productId);
        }
        foreach ($query->orderBy('id')->cursor() as $listing) {
            $run->heartbeat();
            $verify = $this->client->query($account, 'query TargetVariant($id: ID!) { productVariant(id: $id) { id } }', ['id' => $listing->external_id]);
            if (! array_key_exists('productVariant', $verify)) {
                throw new ShopifyException('invalid_data');
            }
            if ($verify['productVariant'] !== null) {
                throw new SyncException('source_changed', true);
            }
            $run->guardedWrite(function () use ($listing) {
                DB::table('shopify_inventory_levels')->where('organization_id', $listing->organization_id)->where('marketplace_listing_id', $listing->id)->update(['is_current' => false]);
                DB::table('marketplace_listings')->where('organization_id', $listing->organization_id)->where('id', $listing->id)->update(['source_status' => 'deleted', 'status' => 'inactive', 'stock_quantity' => null]);
            });
        }
    }

    private function inventory(MarketplaceAccount $account, MarketplaceSyncRun $run): void
    {
        $query = 'query Locations($after: String) { locations(first: 100, after: $after, includeInactive: true, includeLegacy: true) { nodes { id name isActive } pageInfo { hasNextPage endCursor } } }';
        foreach ($this->pages($account, $run, $query, 'locations') as $location) {
            $run->guardedWrite(fn () => DB::table('shopify_locations')->updateOrInsert(['marketplace_account_id' => $account->id, 'external_id' => $location['id']], [
                'organization_id' => $account->organization_id, 'name' => $location['name'], 'is_active' => $location['isActive'], 'source_synced_at' => now()->toIso8601String(), 'seen_run' => $run->reconcile_token,
            ]));
        }
        $run->guardedWrite(fn () => DB::table('shopify_locations')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->where('seen_run', '<>', $run->reconcile_token)->update(['is_active' => false]));
        DB::table('marketplace_listings')->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->whereNotNull('source_data')->where('source_status', '<>', 'deleted')->when($run->target_listing_id, fn ($q) => $q->where('id', $run->target_listing_id))->orderBy('id')->chunkById(100, function ($listings) use ($account, $run) {
            foreach ($listings as $listing) {
                try {
                    $source = json_decode($listing->source_data, true, flags: JSON_THROW_ON_ERROR);
                    $itemId = $source['variant']['inventoryItem']['id'];
                    $tracked = $source['variant']['inventoryItem']['tracked'];
                    $total = 0;
                    $known = $tracked;
                    $seen = false;
                    $query = 'query Levels($id: ID!, $after: String) { inventoryItem(id: $id) { tracked inventoryLevels(first: 100, after: $after) { nodes { id updatedAt location { id } quantities(names: ["available", "on_hand", "incoming", "committed"]) { name quantity } } pageInfo { hasNextPage endCursor } } } }';
                    // Read current tracked on every sync, including inventory-only runs.
                    $run->heartbeat();
                    $info = $this->client->query($account, 'query Item($id: ID!) { inventoryItem(id: $id) { tracked } }', ['id' => $itemId]);
                    if (! isset($info['inventoryItem'])) {
                        throw new ShopifyException('invalid_data');
                    }
                    $tracked = (bool) $info['inventoryItem']['tracked'];
                    $known = $tracked;
                    foreach ($this->pages($account, $run, $query, 'inventoryItem.inventoryLevels', ['id' => $itemId]) as $level) {
                        $location = DB::table('shopify_locations')->where('marketplace_account_id', $account->id)->where('external_id', $level['location']['id'])->first();
                        if (! $location) {
                            throw new ShopifyException('invalid_data');
                        }
                        $quantities = array_column($level['quantities'], 'quantity', 'name');
                        $available = $tracked ? ($quantities['available'] ?? null) : null;
                        if ($location->is_active) {
                            $seen = true;
                            $known = $known && $available !== null;
                            $total += $available ?? 0;
                        }
                        $run->guardedWrite(fn () => DB::table('shopify_inventory_levels')->updateOrInsert(['marketplace_listing_id' => $listing->id, 'shopify_location_id' => $location->id], [
                            'organization_id' => $account->organization_id, 'marketplace_account_id' => $account->id, 'inventory_item_id' => $itemId, 'tracked' => $tracked,
                            'available' => $available, 'on_hand' => $tracked ? ($quantities['on_hand'] ?? null) : null,
                            'incoming' => $tracked ? ($quantities['incoming'] ?? null) : null, 'committed' => $tracked ? ($quantities['committed'] ?? null) : null,
                            'source_updated_at' => $level['updatedAt'], 'source_synced_at' => now()->toIso8601String(), 'seen_run' => $run->reconcile_token, 'is_current' => (bool) $location->is_active,
                        ]));
                    }
                    // Only a successfully paginated item may invalidate its old location snapshots.
                    $run->guardedWrite(function () use ($listing, $run, $seen, $known, $total, $itemId) {
                        DB::table('shopify_inventory_levels')->where('marketplace_listing_id', $listing->id)->where('seen_run', '<>', $run->reconcile_token)->update(['is_current' => false]);
                        $run->heartbeat();
                        DB::table('marketplace_listings')->where('id', $listing->id)->update(['stock_quantity' => $seen && $known && $total >= 0 ? $total : null]);
                        $run->recordResult('inventory', $itemId);
                    });
                } catch (ShopifyException $e) {
                    if ($e->reason !== 'invalid_data') {
                        throw $e;
                    }
                    $this->recordError($run, $listing->external_id, $e->getMessage());
                }
            }
        });
    }

    private function pages(MarketplaceAccount $account, MarketplaceSyncRun $run, string $query, string $path, array $variables = []): \Generator
    {
        $run->heartbeat();
        yield from $this->client->pages($account, $query, $path, $variables, fn (array $page) => $run->checkpoint(['phase' => $path, 'parent_id' => $variables['id'] ?? null, ...$page]));
    }

    private function recordError(MarketplaceSyncRun $run, string $reference, string $message): void
    {
        $run->recordError($reference, 'invalid_data');
    }
}
