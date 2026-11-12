<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;

interface ProductLinks
{
    public function adminUrl(MarketplaceAccount $account, ?string $externalProductId): ?string;
}
