<?php

namespace Database\Factories;

use App\Models\MarketplaceAccount;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketplaceListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'marketplace_account_id' => fn (array $attributes) => MarketplaceAccount::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'external_id' => 'SYNTHETIC-'.fake()->uuid(),
            'external_sku' => null, 'price_minor' => 19900, 'currency' => 'RUB', 'stock_quantity' => 10, 'status' => 'active',
        ];
    }
}
