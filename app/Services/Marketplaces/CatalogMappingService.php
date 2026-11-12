<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CatalogMappingService
{
    public function map(MarketplaceAccount $account, int $listingId, ?int $productId): MarketplaceListing
    {
        return DB::transaction(function () use ($account, $listingId, $productId) {
            $listing = $this->lockedListing($account, $listingId);
            if ($productId !== null) {
                $product = Product::withoutGlobalScopes()->where('organization_id', $account->organization_id)->findOrFail($productId);
                if (auth()->check()) {
                    Gate::authorize('update', $product);
                }
            }
            // Source snapshots use a different validator from editable legacy placements.
            abort_unless($listing->origin === 'imported', 422, 'Сопоставление доступно для внешнего варианта.');
            DB::table('marketplace_listings')->where('id', $listing->id)->update(['product_id' => $productId, 'updated_at' => now()->toIso8601String()]);

            return $listing->fresh();
        });
    }

    public function createLocalProduct(MarketplaceAccount $account, int $listingId, array $attributes): Product
    {
        return DB::transaction(function () use ($account, $listingId, $attributes) {
            $listing = $this->lockedListing($account, $listingId);
            abort_if($listing->product_id !== null, 409, 'Вариант уже сопоставлен.');
            abort_unless($listing->origin === 'imported', 422, 'Требуется внешний вариант.');
            $listing->load('marketplaceProduct');
            $product = new Product;
            $product->forceFill(['organization_id' => $account->organization_id, 'sku' => $attributes['sku'],
                'name' => $attributes['name'] ?? $listing->marketplaceProduct?->title ?? $listing->variant_title,
                'description' => strip_tags($listing->marketplaceProduct?->description ?? ''), 'base_price_minor' => $listing->price_minor,
                'currency' => $listing->currency, 'is_active' => true])->save();
            DB::table('marketplace_listings')->where('id', $listing->id)->update(['product_id' => $product->id, 'updated_at' => now()->toIso8601String()]);

            return $product;
        });
    }

    private function lockedListing(MarketplaceAccount $account, int $listingId): MarketplaceListing
    {
        if (auth()->check()) {
            Gate::authorize('update', $account);
            abort_unless($account->organization_id === OrganizationContext::id(), 404);
        }
        MarketplaceAccount::withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);

        return MarketplaceListing::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('marketplace_account_id', $account->id)->lockForUpdate()->findOrFail($listingId);
    }
}
