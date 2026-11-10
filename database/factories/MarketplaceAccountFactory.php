<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketplaceAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(), 'marketplace' => 'wildberries',
            'name' => 'Тестовое подключение '.fake()->word(), 'status' => 'demo', 'is_demo' => true,
        ];
    }
}
