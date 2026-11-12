<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceSyncRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConnectionReadService
{
    public function attach(Collection $accounts): void
    {
        if ($accounts->isEmpty()) {
            return;
        }
        $orgs = $accounts->pluck('organization_id')->unique();
        $ids = $accounts->pluck('id');
        $stats = DB::table('marketplace_listings')->whereIn('organization_id', $orgs)->whereIn('marketplace_account_id', $ids)->groupBy('marketplace_account_id')->selectRaw('marketplace_account_id,count(*) variants,count(distinct marketplace_product_id) products,count(*) filter(where product_id is null) unmapped')->get()->keyBy('marketplace_account_id');
        $ranked = DB::table('marketplace_sync_runs')->whereIn('organization_id', $orgs)->whereIn('marketplace_account_id', $ids)->selectRaw('*,row_number() over(partition by marketplace_account_id order by created_at desc,id desc) position');
        $runs = MarketplaceSyncRun::withoutGlobalScopes()->fromSub($ranked, 'marketplace_sync_runs')->where('position', 1)->get()->keyBy('marketplace_account_id');
        foreach ($runs as $run) {
            unset($run->position);
        }
        foreach ($accounts as $a) {
            $a->setRelation('syncSummary', ['stats' => $stats->get($a->id), 'run' => $runs->get($a->id)]);
        }
    }
}
