<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('shopify_locations', 'marketplace_locations');
        Schema::rename('shopify_inventory_levels', 'marketplace_inventory_levels');
        Schema::table('marketplace_inventory_levels', function (Blueprint $t) {
            $t->renameColumn('shopify_location_id', 'marketplace_location_id');
        });
        Schema::table('marketplace_locations', function (Blueprint $t) {
            $t->jsonb('metadata')->nullable();
            $t->timestampsTz();
        });
        Schema::table('marketplace_inventory_levels', function (Blueprint $t) {
            // Nullable compatibility alias, never required by a generic provider.
            $t->string('inventory_item_id')->nullable()->change();
            $t->jsonb('metadata')->nullable();
            $t->jsonb('quantities')->nullable();
            $t->string('stale_reason', 30)->nullable();
            $t->timestampsTz();
            $t->foreign(['organization_id', 'marketplace_account_id', 'marketplace_listing_id'], 'inventory_listing_account_fk')
                ->references(['organization_id', 'marketplace_account_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $t->index(['organization_id', 'marketplace_account_id', 'is_current'], 'inventory_current_index');
        });
        DB::statement('UPDATE marketplace_locations SET created_at=source_synced_at, updated_at=source_synced_at');
        DB::statement("UPDATE marketplace_inventory_levels SET created_at=source_synced_at, updated_at=source_synced_at, quantities=jsonb_build_object('available',available,'on_hand',on_hand,'incoming',incoming,'committed',committed), metadata=jsonb_build_object('inventory_item_id',inventory_item_id), stale_reason=CASE WHEN is_current THEN NULL ELSE 'legacy_stale' END");
        // One physical source. Legacy readers retain their column names and IDs.
        DB::statement('CREATE VIEW shopify_locations AS SELECT * FROM marketplace_locations');
        DB::statement('CREATE VIEW shopify_inventory_levels AS SELECT id, organization_id, marketplace_account_id, marketplace_listing_id, marketplace_location_id AS shopify_location_id, inventory_item_id, tracked, available, on_hand, committed, incoming, source_updated_at, source_synced_at, seen_run, is_current FROM marketplace_inventory_levels');
    }

    public function down(): void
    {
        if (DB::table('marketplace_inventory_levels')->exists() || DB::table('marketplace_locations')->exists()) {
            throw new LogicException('Inventory data exists; restore or migrate it explicitly before rollback.');
        }
        DB::statement('DROP VIEW shopify_inventory_levels');
        DB::statement('DROP VIEW shopify_locations');
        Schema::table('marketplace_inventory_levels', function (Blueprint $t) {
            $t->dropForeign('inventory_listing_account_fk');
            $t->dropIndex('inventory_current_index');
            $t->dropColumn(['metadata', 'quantities', 'stale_reason', 'created_at', 'updated_at']);
            $t->string('inventory_item_id')->nullable(false)->change();
            $t->renameColumn('marketplace_location_id', 'shopify_location_id');
        });
        Schema::table('marketplace_locations', fn (Blueprint $t) => $t->dropColumn(['metadata', 'created_at', 'updated_at']));
        Schema::rename('marketplace_inventory_levels', 'shopify_inventory_levels');
        Schema::rename('marketplace_locations', 'shopify_locations');
    }
};
