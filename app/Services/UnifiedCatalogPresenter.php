<?php

namespace App\Services;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceSyncRun;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Services\Marketplaces\ProductLinks;
use App\Services\Marketplaces\ProviderRegistry;
use App\Support\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UnifiedCatalogPresenter
{
    private function listings()
    {
        return MarketplaceListing::query()->select(['marketplace_listings.id', 'organization_id', 'marketplace_account_id', 'product_id', 'marketplace_product_id', 'external_id', 'external_sku', 'variant_title', 'barcode', 'price_minor', 'currency', 'status', 'source_status', 'source_synced_at', 'source_updated_at', 'origin', 'stock_quantity', 'updated_at'])
            ->with(['product' => fn ($q) => $q->select('id', 'name', 'sku'), 'marketplaceAccount' => fn ($q) => $q->select('id', 'organization_id', 'marketplace', 'name', 'status', 'is_demo', 'configuration', 'shop_domain'), 'marketplaceProduct' => fn ($q) => $q->select('id', 'organization_id', 'external_id', 'title')->selectRaw("jsonb_path_query_array(images, '$[0 to 11]') as images")]);
    }

    private function gallery(array $images): array
    {
        return array_values(array_slice(array_filter(array_map(function ($image) {
            $url = is_array($image) ? ($image['url'] ?? '') : $image;

            return is_string($url) && preg_match('~^https?://~i', $url) ? ['url' => $url, 'alt' => is_array($image) ? ($image['altText'] ?? '') : ''] : null;
        }, $images)), 0, 12));
    }

    private function serializeSources(Collection $listings): array
    {
        $org = OrganizationContext::id();
        $ids = $listings->pluck('id');
        $stats = DB::table('marketplace_inventory_levels')->where('organization_id', $org)->whereIn('marketplace_listing_id', $ids)->groupBy('marketplace_listing_id')->selectRaw('marketplace_listing_id,count(*) levels,count(available) known,sum(available) quantity,bool_and(tracked) tracked,bool_and(is_current) current,max(source_synced_at) synced_at')->get()->keyBy('marketplace_listing_id');
        $runs = MarketplaceSyncRun::query()->whereIn('marketplace_account_id', $listings->pluck('marketplace_account_id'))->whereIn('status', ['queued', 'running'])->get()->keyBy('marketplace_account_id');

        return $listings->map(function ($l) use ($stats, $runs) {
            $account = $l->marketplaceAccount;
            $provider = app(ProviderRegistry::class)->get($account->marketplace);
            $s = $stats->get($l->id);
            $run = $runs->get($account->id);
            if ($run && $run->scope !== 'account' && ! ($run->scope === 'variant' && $run->target_listing_id === $l->id) && ! ($run->scope === 'product' && $run->target_product_id === $l->marketplace_product_id)) {
                $run = null;
            }

            return ['id' => $l->id, 'product_id' => $l->product_id, 'local_product' => $l->product?->only(['id', 'name', 'sku']), 'marketplace_product_id' => $l->marketplace_product_id, 'title' => $l->marketplaceProduct?->title, 'variant_title' => $l->variant_title, 'sku' => $l->external_sku, 'barcode' => $l->barcode, 'external_id' => $l->external_id, 'origin' => $l->origin, 'status' => $l->source_status ?? $l->status, 'price_minor' => $l->price_minor, 'currency' => $l->currency, 'source_synced_at' => $l->source_synced_at?->toISOString(), 'source_updated_at' => $l->source_updated_at?->toISOString(),
                'account' => ['id' => $account->id, 'name' => $account->name, 'provider' => $account->marketplace, 'provider_name' => $provider->definition()['name'], 'is_demo' => $account->is_demo],
                'capabilities' => $provider->definition()['capabilities'], 'admin_url' => $provider instanceof ProductLinks ? $provider->adminUrl($account, $l->marketplaceProduct?->external_id) : null,
                'inventory' => ['quantity' => $l->origin === 'imported' ? ($s && $s->known === $s->levels && $s->tracked ? (int) $s->quantity : null) : $l->stock_quantity, 'levels' => (int) ($s->levels ?? 0), 'tracked' => $s?->tracked, 'current' => $s?->current, 'synced_at' => $s?->synced_at, 'origin' => $l->origin === 'demo' ? 'demo' : ($l->origin === 'imported' ? 'external_snapshot' : 'local_listing')],
                'active_run' => $run?->only(['id', 'status', 'scope', 'target_listing_id', 'processed', 'errors_count', 'error_message', 'created_at', 'finished_at']), 'images' => $this->gallery($l->marketplaceProduct?->images ?? [])];
        })->all();
    }

    public function page(Collection $rows): Collection
    {
        $org = OrganizationContext::id();
        $local = $rows->where('kind', 'local')->pluck('entity_id');
        $parents = $rows->where('kind', 'external_product')->pluck('entity_id');
        $variants = $rows->where('kind', 'external_variant')->pluck('entity_id');
        $ranked = DB::table('marketplace_listings')->where('organization_id', $org)->where(fn ($q) => $q->whereIn('product_id', $local)->orWhere(fn ($q) => $q->whereNull('product_id')->whereIn('marketplace_product_id', $parents))->orWhereIn('id', $variants));
        $partition = "CASE WHEN product_id IS NOT NULL THEN 'local:'||product_id WHEN marketplace_product_id IS NOT NULL THEN 'external_product:'||marketplace_product_id ELSE 'external_variant:'||id END";
        $ranked->selectRaw("*,row_number() over(partition by $partition order by id) preview_position,count(*) over(partition by $partition) source_count");
        $listings = $this->listings()->fromSub($ranked, 'marketplace_listings')->where('preview_position', '<=', 8)->addSelect('source_count')->get();
        $sources = collect($this->serializeSources($listings))->map(fn ($s) => [...$s, 'images' => array_slice($s['images'], 0, 1)]);
        $physical = WarehouseStock::query()->whereIn('product_id', $local)->groupBy('product_id')->selectRaw('product_id,sum(quantity) quantity')->pluck('quantity', 'product_id');

        return $rows->map(function ($row) use ($sources, $physical, $listings) {
            $items = $sources->filter(fn ($l) => $row->kind === 'local' ? $l['product_id'] === $row->entity_id : ($row->kind === 'external_product' ? $l['product_id'] === null && $l['marketplace_product_id'] === $row->entity_id : $l['id'] === $row->entity_id))->values();
            $count = $listings->first(fn ($l) => $row->kind === 'local' ? $l->product_id === $row->entity_id : ($row->kind === 'external_product' ? $l->product_id === null && $l->marketplace_product_id === $row->entity_id : $l->id === $row->entity_id))?->source_count ?? 0;

            return ['key' => $row->kind.':'.$row->entity_id, 'kind' => $row->kind, 'entity_id' => $row->entity_id, 'title' => $row->title, 'sku' => $row->sku, 'price_minor' => $row->price_minor, 'currency' => $row->currency, 'status' => $row->status, 'mapping' => $row->kind === 'local' ? ($count ? 'mapped' : 'local') : 'unmapped', 'images' => $items->pluck('images')->flatten(1)->unique('url')->take(1)->values()->all(), 'sources' => $items->all(), 'sources_count' => (int) $count, 'physical_quantity' => $row->kind === 'local' ? (int) ($physical[$row->entity_id] ?? 0) : null, 'source_synced_at' => $items->pluck('source_synced_at')->filter()->max(), 'updated_at' => $row->updated_at];
        });
    }

    public function detail(string $kind, int $id, int $page = 1): array
    {
        $q = $this->listings();
        $name = '';
        $sku = null;
        $description = null;
        $images = [];
        $physical = [];
        $local = null;
        if ($kind === 'local') {
            $local = Product::query()->findOrFail($id);
            $name = $local->name;
            $sku = $local->sku;
            $description = $local->description;
            $q->where('product_id', $id);
            $physical = WarehouseStock::query()->where('product_id', $id)->with('warehouse:id,name')->orderBy('id')->limit(100)->get()->map(fn ($s) => ['warehouse_id' => $s->warehouse_id, 'warehouse_name' => $s->warehouse->name, 'quantity' => $s->quantity])->all();
        } elseif ($kind === 'external_product') {
            $parent = MarketplaceProduct::query()->select('id', 'title', 'description', 'external_id')->selectRaw("jsonb_path_query_array(images, '$[0 to 11]') as images")->findOrFail($id);
            $name = $parent->title;
            $description = strip_tags($parent->description ?? '');
            $images = $this->gallery($parent->images ?? []);
            $q->where('marketplace_product_id', $id);
        } else {
            $listing = MarketplaceListing::query()->select('id', 'marketplace_product_id', 'variant_title', 'external_sku', 'external_id')->with('marketplaceProduct:id,title,description')->findOrFail($id);
            $name = $listing->marketplaceProduct?->title ?? $listing->variant_title ?? $listing->external_sku ?? $listing->external_id;
            $description = strip_tags($listing->marketplaceProduct?->description ?? '');
            $sku = $listing->external_sku;
            $q->whereKey($id);
        }
        $paginator = $q->orderBy('id')->paginate(20, ['*'], 'page', $page);
        $sources = $this->serializeSources($paginator->getCollection());
        if (! $images) {
            $images = collect($sources)->pluck('images')->flatten(1)->unique('url')->take(12)->values()->all();
        }
        if (! $local) {
            $productIds = array_values(array_unique(array_filter(array_column($sources, 'product_id'))));
            $physical = WarehouseStock::query()->whereIn('product_id', $productIds)->with(['warehouse:id,name', 'product:id,sku'])->orderBy('id')->limit(100)->get()->map(fn ($s) => ['product_id' => $s->product_id, 'sku' => $s->product->sku, 'warehouse_id' => $s->warehouse_id, 'warehouse_name' => $s->warehouse->name, 'quantity' => $s->quantity])->all();
        }
        $ids = array_column($sources, 'id');
        $levels = DB::table('marketplace_inventory_levels as i')->join('marketplace_locations as l', 'l.id', '=', 'i.marketplace_location_id')->where('i.organization_id', OrganizationContext::id())->whereIn('i.marketplace_listing_id', $ids)->orderBy('i.id')->select('i.id', 'i.marketplace_listing_id', 'i.available', 'i.tracked', 'i.is_current', 'i.stale_reason', 'i.source_synced_at', 'i.source_updated_at', 'l.name as location_name', 'l.is_active as location_active', 'l.warehouse_id');
        $levelCount = (clone $levels)->count();

        return ['key' => $kind.':'.$id, 'kind' => $kind, 'entity_id' => $id, 'title' => $name, 'sku' => $sku, 'description' => $description, 'images' => $images, 'local_product' => $local?->only(['id', 'sku', 'name', 'description', 'base_price_minor', 'currency', 'is_active', 'updated_at']), 'sources' => $sources, 'sources_count' => $paginator->total(), 'sources_page' => $paginator->currentPage(), 'sources_last_page' => $paginator->lastPage(), 'physical_stocks' => $physical, 'inventory' => $levels->limit(200)->get(), 'inventory_total' => $levelCount];
    }
}
