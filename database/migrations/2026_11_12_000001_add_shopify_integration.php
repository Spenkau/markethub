<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE marketplace_accounts DROP CONSTRAINT accounts_values_check');
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT accounts_values_check CHECK (marketplace IN ('wildberries','ozon','shopify') AND status IN ('demo','active','disabled') AND length(trim(name)) > 0)");
        Schema::table('marketplace_accounts', function (Blueprint $t) {
            $t->string('shop_domain')->nullable();
            $t->text('shopify_client_id')->nullable();
            $t->text('shopify_client_secret')->nullable();
            $t->string('sync_frequency', 10)->default('hourly');
            $t->boolean('sync_products')->default(true);
            $t->boolean('sync_inventory')->default(true);
            $t->timestampTz('initial_import_at')->nullable();
            $t->timestampTz('last_synced_at')->nullable();
            $t->timestampTz('last_products_synced_at')->nullable();
            $t->timestampTz('last_full_sync_at')->nullable();
            $t->timestampTz('next_sync_at')->nullable()->index();
            $t->string('api_status')->default('unchecked');
            $t->timestampTz('api_checked_at')->nullable();
            $t->jsonb('shopify_scopes')->nullable();
            $t->char('shop_currency', 3)->nullable();
            $t->unique(['organization_id', 'shop_domain']);
        });
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT sync_frequency_check CHECK (sync_frequency IN ('manual','hourly','daily'))");
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropUnique('listing_product_account_unique');
            $t->unsignedBigInteger('product_id')->nullable()->change();
            $t->integer('stock_quantity')->nullable()->change();
            $t->jsonb('source_data')->nullable();
            $t->string('source_status')->nullable();
            $t->timestampTz('source_synced_at')->nullable();
            $t->uuid('source_seen_run')->nullable();
            $t->unique(['organization_id', 'id'], 'listings_org_id_unique');
        });
        // Keep the original one-listing-per-product invariant for local WB/Ozon data.
        DB::statement('CREATE UNIQUE INDEX listing_local_product_account_unique ON marketplace_listings (product_id, marketplace_account_id) WHERE source_data IS NULL');
        Schema::create('shopify_sync_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id');
            $t->string('trigger', 10);
            $t->string('status', 12)->default('queued');
            $t->boolean('sync_products');
            $t->boolean('sync_inventory');
            $t->boolean('full_reconcile')->default(true);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('created')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('errors_count')->default(0);
            $t->jsonb('details')->nullable();
            $t->text('error_message')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->foreign(['organization_id', 'marketplace_account_id'])->references(['organization_id', 'id'])->on('marketplace_accounts')->restrictOnDelete();
            $t->index(['organization_id', 'marketplace_account_id', 'created_at']);
        });
        DB::statement("CREATE UNIQUE INDEX shopify_one_active_run ON shopify_sync_runs (marketplace_account_id) WHERE status IN ('queued','running')");
        DB::statement("ALTER TABLE shopify_sync_runs ADD CONSTRAINT sync_run_values_check CHECK (status IN ('queued','running','completed','partial','failed') AND trigger IN ('manual','automatic'))");
        Schema::create('shopify_locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id');
            $t->string('external_id');
            $t->string('name');
            $t->boolean('is_active');
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->timestampTz('source_synced_at');
            $t->uuid('seen_run');
            $t->unique(['marketplace_account_id', 'external_id']);
            $t->unique(['organization_id', 'marketplace_account_id', 'id'], 'shopify_location_org_unique');
            $t->foreign(['organization_id', 'marketplace_account_id'])->references(['organization_id', 'id'])->on('marketplace_accounts')->restrictOnDelete();
            $t->foreign(['organization_id', 'warehouse_id'])->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
        });
        Schema::create('shopify_inventory_levels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id');
            $t->unsignedBigInteger('marketplace_listing_id');
            $t->unsignedBigInteger('shopify_location_id');
            $t->string('inventory_item_id');
            $t->boolean('tracked');
            $t->integer('available')->nullable();
            $t->integer('on_hand')->nullable();
            $t->integer('committed')->nullable();
            $t->integer('incoming')->nullable();
            $t->timestampTz('source_updated_at')->nullable();
            $t->timestampTz('source_synced_at');
            $t->uuid('seen_run');
            $t->boolean('is_current')->default(true);
            $t->unique(['marketplace_listing_id', 'shopify_location_id'], 'shopify_level_unique');
            $t->foreign(['organization_id', 'marketplace_listing_id'])->references(['organization_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $t->foreign(['organization_id', 'marketplace_account_id', 'shopify_location_id'], 'shopify_level_location_fk')->references(['organization_id', 'marketplace_account_id', 'id'])->on('shopify_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('marketplace_accounts')->where('marketplace', 'shopify')->exists()) {
            throw new LogicException('Disconnect and migrate Shopify data explicitly before rollback.');
        }
        Schema::dropIfExists('shopify_inventory_levels');
        Schema::dropIfExists('shopify_locations');
        Schema::dropIfExists('shopify_sync_runs');
        DB::statement('DROP INDEX IF EXISTS listing_local_product_account_unique');
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropUnique('listings_org_id_unique');
            $t->dropColumn(['source_data', 'source_status', 'source_synced_at', 'source_seen_run']);
            $t->unsignedBigInteger('product_id')->nullable(false)->change();
            $t->integer('stock_quantity')->nullable(false)->change();
            $t->unique(['product_id', 'marketplace_account_id'], 'listing_product_account_unique');
        });
        Schema::table('marketplace_accounts', function (Blueprint $t) {
            $t->dropUnique(['organization_id', 'shop_domain']);
            $t->dropColumn(['shop_domain', 'shopify_client_id', 'shopify_client_secret', 'sync_frequency', 'sync_products', 'sync_inventory', 'initial_import_at', 'last_synced_at', 'last_products_synced_at', 'last_full_sync_at', 'next_sync_at', 'api_status', 'api_checked_at', 'shopify_scopes', 'shop_currency']);
        });
        DB::statement('ALTER TABLE marketplace_accounts DROP CONSTRAINT accounts_values_check');
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT accounts_values_check CHECK (marketplace IN ('wildberries','ozon') AND status IN ('demo','active','disabled') AND length(trim(name)) > 0)");
    }
};
