<?php

namespace App\Services\Shopify;

use App\Services\Marketplaces\SyncException;

class ShopifyException extends SyncException
{
    public function __construct(string $reason, bool $retryable = false)
    {
        parent::__construct($reason, $retryable);
    }
}
