<?php

namespace App\Console\Commands;

use App\Services\Marketplaces\MarketplaceBackfill;
use Illuminate\Console\Command;

class BackfillMarketplaces extends Command
{
    protected $signature = 'marketplaces:backfill';

    protected $description = 'Idempotently normalize existing external products and encrypt legacy credentials';

    public function handle(MarketplaceBackfill $backfill): int
    {
        $result = $backfill->run();
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['skipped'] ? self::FAILURE : self::SUCCESS;
    }
}
