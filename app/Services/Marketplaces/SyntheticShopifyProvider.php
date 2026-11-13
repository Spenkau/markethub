<?php

namespace App\Services\Marketplaces;

/** Fixture display only: no client, importer, checker or outbound capability. */
class SyntheticShopifyProvider extends DemoProvider
{
    public function definition(): array
    {
        return [...parent::definition(), 'capabilities' => ['products', 'inventory', 'locations', 'history']];
    }
}
