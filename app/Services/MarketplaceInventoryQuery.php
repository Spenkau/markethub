<?php

namespace App\Services;

use App\Http\Requests\Api\V1\IndexRequest;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceInventoryQuery
{
    /** One row per real location; demo/local listings remain one undetailed row. Never sum cache and levels. */
    public function snapshotRows(Builder $query): Builder
    {
        $query->leftJoin('marketplace_inventory_levels as inventory', function ($join) {
            $join->on('inventory.marketplace_listing_id', '=', 'marketplace_listings.id')->on('inventory.organization_id', '=', 'marketplace_listings.organization_id')->on('inventory.marketplace_account_id', '=', 'marketplace_listings.marketplace_account_id')->where('marketplace_listings.origin', '=', 'imported');
        })->leftJoin('marketplace_locations as location', 'location.id', '=', 'inventory.marketplace_location_id')
            ->select('marketplace_listings.*', 'inventory.id as inventory_level_id', 'inventory.marketplace_location_id', 'location.name as location_name', 'location.is_active as location_active', 'location.warehouse_id', 'inventory.tracked', 'inventory.quantities', 'inventory.is_current', 'inventory.stale_reason', 'inventory.source_updated_at as inventory_updated_at', 'inventory.source_synced_at as inventory_synced_at')
            ->selectRaw("CASE WHEN marketplace_listings.origin = 'imported' THEN inventory.available ELSE marketplace_listings.stock_quantity END AS stock_quantity");

        return $query;
    }

    public function decorate(Builder $query, IndexRequest $request): Builder
    {
        $this->snapshotRows($query);
        if ($request->filled('location_id')) {
            $query->where('inventory.marketplace_location_id', $request->integer('location_id'));
        }
        if ($request->has('is_current')) {
            $query->where('inventory.is_current', $request->boolean('is_current'));
        }
        if ($request->has('tracked')) {
            $query->where('inventory.tracked', $request->boolean('tracked'));
        }
        if ($request->has('location_active')) {
            $query->where('location.is_active', $request->boolean('location_active'));
        }
        $quantity = "CASE WHEN marketplace_listings.origin = 'imported' THEN inventory.available ELSE marketplace_listings.stock_quantity END";
        match ($request->validated('stock')) {
            'zero' => $query->whereRaw("($quantity)=0"),
            'low' => $query->whereRaw("($quantity) BETWEEN 1 AND 5"),
            'positive' => $query->whereRaw("($quantity)>0"),
            'negative' => $query->whereRaw("($quantity)<0"),
            'unknown' => $query->whereRaw("($quantity) IS NULL"),
            default => null
        };

        return $query;
    }
}
