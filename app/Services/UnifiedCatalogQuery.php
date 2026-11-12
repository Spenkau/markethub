<?php

namespace App\Services;

use App\Http\Requests\Api\V1\CatalogIndexRequest;
use App\Support\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UnifiedCatalogQuery
{
    private function listings(int $org): Builder
    {
        return DB::table('marketplace_listings as l')->join('marketplace_accounts as a', 'a.id', '=', 'l.marketplace_account_id')->leftJoin('marketplace_products as ep', 'ep.id', '=', 'l.marketplace_product_id')->where('l.organization_id', $org)->where('a.organization_id', $org);
    }

    private function filter(Builder $q, CatalogIndexRequest $r, bool $search = true): Builder
    {
        if ($r->filled('marketplace')) {
            $q->where('a.marketplace', $r->validated('marketplace'));
        }
        if ($r->filled('marketplace_account_id')) {
            $q->where('a.id', $r->integer('marketplace_account_id'));
        }
        if ($r->has('is_demo')) {
            $q->where('a.is_demo', $r->boolean('is_demo'));
        }
        if ($r->filled('origin')) {
            $q->where('l.origin', $r->validated('origin'));
        }
        if ($search && $r->filled('search')) {
            $s = '%'.$r->validated('search').'%';
            $q->where(fn ($q) => $q->where('ep.title', 'ilike', $s)->orWhere('l.variant_title', 'ilike', $s)->orWhere('l.external_sku', 'ilike', $s)->orWhere('l.barcode', 'ilike', $s)->orWhere('l.external_id', 'ilike', $s)->orWhere('ep.external_id', 'ilike', $s));
        }

        return $q;
    }

    public function query(CatalogIndexRequest $r): Builder
    {
        $org = OrganizationContext::id();
        abort_if(! $org, 403);
        $local = DB::table('products as p')->where('p.organization_id', $org)->selectRaw("'local'::text kind,p.id entity_id,p.name title,p.sku,p.base_price_minor price_minor,p.currency,CASE WHEN p.is_active THEN 'active' ELSE 'inactive' END status,p.created_at,p.updated_at");
        $source = $this->filter($this->listings($org), $r, false)->whereColumn('l.product_id', 'p.id')->selectRaw('1');
        if ($r->filled('marketplace') || $r->filled('marketplace_account_id') || $r->has('is_demo') || $r->filled('origin') || $r->validated('mapping') === 'mapped') {
            $local->whereExists($source);
        }
        if ($r->validated('mapping') === 'unmapped' || $r->validated('kind') === 'external') {
            $local->whereRaw('false');
        }
        if ($r->filled('search')) {
            $search = '%'.$r->validated('search').'%';
            $match = $this->filter($this->listings($org), $r)->whereColumn('l.product_id', 'p.id')->selectRaw('1');
            $local->where(fn ($q) => $q->where('p.name', 'ilike', $search)->orWhere('p.sku', 'ilike', $search)->orWhereExists($match));
        }
        $external = $this->filter($this->listings($org), $r)->whereNull('l.product_id');
        if ($r->validated('kind') === 'local' || $r->validated('mapping') === 'mapped') {
            $external->whereRaw('false');
        }
        $external->selectRaw("CASE WHEN l.marketplace_product_id IS NULL THEN 'external_variant' ELSE 'external_product' END kind,coalesce(l.marketplace_product_id,l.id) entity_id,coalesce(ep.title,l.variant_title,l.external_sku,l.external_id) title,min(l.external_sku) sku,min(l.price_minor) price_minor,min(l.currency) currency,CASE WHEN bool_or(l.status='active') THEN 'active' ELSE 'inactive' END status,min(l.created_at) created_at,max(l.updated_at) updated_at")
            ->groupByRaw("CASE WHEN l.marketplace_product_id IS NULL THEN 'external_variant' ELSE 'external_product' END,coalesce(l.marketplace_product_id,l.id),coalesce(ep.title,l.variant_title,l.external_sku,l.external_id)");
        // Parent groups use the parent title; a parent without one is not a valid normalized parent.
        $q = DB::query()->fromSub($local->unionAll($external), 'catalog');
        if ($r->has('is_active')) {
            $q->where('status', $r->boolean('is_active') ? 'active' : 'inactive');
        }
        if (in_array($r->validated('status'), ['active', 'inactive'], true)) {
            $q->where('status', $r->validated('status'));
        }

        return $q->orderBy(match ($r->validated('sort', 'id')) {
            'name' => 'title','price_minor' => 'price_minor','sku' => 'sku','created_at' => 'created_at',default => 'entity_id'
        }, $r->validated('direction', 'asc'))->orderBy('kind')->orderBy('entity_id');
    }
}
