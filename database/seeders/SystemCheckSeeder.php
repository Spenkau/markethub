<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemCheckSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('system_checks')->upsert([
            ['key' => 'application', 'value' => 'MarketHub'],
            ['key' => 'environment', 'value' => 'local'],
        ], ['key'], ['value']);
    }
}
