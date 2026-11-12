<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $t) {
            $t->unsignedInteger('products_processed')->default(0);
            $t->unsignedInteger('variants_processed')->default(0);
            $t->unsignedInteger('inventory_processed')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('shopify_sync_runs', fn (Blueprint $t) => $t->dropColumn(['products_processed', 'variants_processed', 'inventory_processed']));
    }
};
