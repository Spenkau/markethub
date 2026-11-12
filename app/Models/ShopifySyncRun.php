<?php

namespace App\Models;

/** Compatibility model: the only physical history is marketplace_sync_runs. */
class ShopifySyncRun extends MarketplaceSyncRun
{
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['trigger'] = in_array($data['trigger'] ?? '', ['scheduled', 'automatic'], true) ? 'automatic' : 'manual';
        if (($data['scope'] ?? 'account') !== 'account') {
            $data['full_reconcile'] = false;
        }

        return $data;
    }
}
