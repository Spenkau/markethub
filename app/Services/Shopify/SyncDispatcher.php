<?php

namespace App\Services\Shopify;

use App\Models\MarketplaceAccount;
use App\Models\ShopifySyncRun;

/** Compatibility facade. No independent scheduler or history. */
class SyncDispatcher extends \App\Services\Marketplaces\SyncDispatcher
{
    public function enqueue(MarketplaceAccount $account, string $trigger = 'manual', ?int $listingId = null, bool $confirmInitial = false, array $options = []): ShopifySyncRun
    {
        $run = parent::enqueue($account, $trigger, $listingId, $confirmInitial, $options);

        return ShopifySyncRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->findOrFail($run->id);
    }
}
