<?php

use App\Services\Marketplaces\DemoProvider;
use App\Services\Marketplaces\ShopifyProvider;
use App\Services\Marketplaces\SyntheticShopifyProvider;

return ['sync' => ['connection' => env('QUEUE_CONNECTION', 'redis'), 'queue' => 'marketplaces', 'max_attempts' => 3, 'execution_budget' => 1500, 'heartbeat_stale' => 300, 'queued_stale' => 300, 'max_dispatches' => 12], 'providers' => [
    'wildberries' => ['adapter' => DemoProvider::class, 'name' => 'Wildberries'],
    'ozon' => ['adapter' => DemoProvider::class, 'name' => 'Ozon'],
    'shopify' => ['adapter' => ShopifyProvider::class, 'name' => 'Shopify'],
    'shopify_demo' => ['adapter' => SyntheticShopifyProvider::class, 'name' => 'Shopify · synthetic demo'],
]];
