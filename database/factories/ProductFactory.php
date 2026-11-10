<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'sku' => fake()->unique()->bothify('SKU-########'),
            'name' => 'Синтетический товар '.fake()->word(),
            'description' => 'Синтетические тестовые данные.',
            'base_price_minor' => fake()->numberBetween(100, 100000),
            'currency' => 'RUB', 'is_active' => true,
        ];
    }
}
