<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $table) {
            $table->foreignId('target_listing_id')->nullable()->constrained('marketplace_listings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_sync_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('target_listing_id'));
    }
};
