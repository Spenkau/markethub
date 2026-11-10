<?php

namespace Tests\Feature;

use Database\Seeders\SystemCheckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_seeded_records_from_database(): void
    {
        $this->seed(SystemCheckSeeder::class);
        $this->seed(SystemCheckSeeder::class);

        $this->assertDatabaseCount('system_checks', 2);
        $this->getJson('/api/system-checks')->assertOk()->assertExactJson([
            'data' => [
                ['key' => 'application', 'value' => 'MarketHub'],
                ['key' => 'environment', 'value' => 'local'],
            ],
        ]);
    }
}
