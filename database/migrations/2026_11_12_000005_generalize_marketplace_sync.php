<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('shopify_sync_runs', 'marketplace_sync_runs');
        DB::statement('ALTER INDEX shopify_one_active_run RENAME TO marketplace_one_active_run');
        DB::statement('ALTER TABLE marketplace_sync_runs DROP CONSTRAINT sync_run_values_check');
        Schema::table('marketplace_sync_runs', function (Blueprint $t) {
            $t->dropForeign('shopify_sync_runs_target_listing_id_foreign');
            $t->string('provider', 20)->nullable();
            $t->string('scope', 12)->default('account');
            $t->unsignedBigInteger('target_product_id')->nullable();
            $t->unsignedInteger('attempt')->default(0);
            $t->string('dispatch_state', 12)->default('pending');
            $t->unsignedInteger('dispatch_attempts')->default(0);
            $t->timestampTz('dispatch_lease_at')->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('next_dispatch_at')->nullable();
            $t->timestampTz('heartbeat_at')->nullable();
            $t->timestampTz('attempt_started_at')->nullable();
            $t->uuid('reconcile_token')->nullable();
            $t->jsonb('checkpoint')->nullable();
            $t->jsonb('attempt_progress')->nullable();
            $t->jsonb('attempt_errors')->nullable();
            $t->unique(['organization_id', 'marketplace_account_id', 'id'], 'sync_run_identity');
            $t->foreign(['organization_id', 'marketplace_account_id', 'target_listing_id'], 'sync_target_listing_fk')->references(['organization_id', 'marketplace_account_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $t->foreign(['organization_id', 'marketplace_account_id', 'target_product_id'], 'sync_target_product_fk')->references(['organization_id', 'marketplace_account_id', 'id'])->on('marketplace_products')->restrictOnDelete();
            $t->index(['status', 'dispatch_state', 'next_dispatch_at'], 'sync_dispatch_due');
            $t->index(['status', 'heartbeat_at'], 'sync_heartbeat_due');
        });
        DB::statement("UPDATE marketplace_sync_runs r SET provider = a.marketplace, scope = CASE WHEN target_listing_id IS NULL THEN 'account' ELSE 'variant' END, dispatch_state = 'published' FROM marketplace_accounts a WHERE a.id=r.marketplace_account_id AND a.organization_id=r.organization_id");
        // Historical columns and UUIDs stay intact. New point runs are explicitly not full scans.
        DB::statement('ALTER TABLE marketplace_sync_runs ALTER COLUMN provider SET NOT NULL');
        DB::statement("ALTER TABLE marketplace_sync_runs ADD CONSTRAINT sync_run_values_check CHECK (status IN ('queued','running','completed','partial','failed') AND trigger IN ('manual','automatic','scheduled','initial','retry') AND dispatch_state IN ('pending','publishing','published'))");
        DB::statement("ALTER TABLE marketplace_sync_runs ADD CONSTRAINT sync_scope_check CHECK ((scope='account' AND target_listing_id IS NULL AND target_product_id IS NULL) OR (scope='variant' AND target_listing_id IS NOT NULL AND target_product_id IS NULL) OR (scope='product' AND target_listing_id IS NULL AND target_product_id IS NOT NULL))");
        Schema::create('marketplace_sync_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id');
            $t->uuid('marketplace_sync_run_id');
            $t->string('kind', 12);
            $t->string('external_id');
            $t->string('outcome', 12);
            $t->unsignedInteger('attempt');
            $t->unique(['marketplace_sync_run_id', 'kind', 'external_id'], 'sync_unique_result');
            $t->foreign(['organization_id', 'marketplace_account_id', 'marketplace_sync_run_id'], 'sync_result_run_fk')->references(['organization_id', 'marketplace_account_id', 'id'])->on('marketplace_sync_runs')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE marketplace_sync_results ADD CONSTRAINT sync_result_values_check CHECK (kind IN ('product','variant','inventory') AND outcome IN ('created','updated','processed','skipped'))");
        // One physical history, an updatable legacy SQL view for old read/update callers.
        DB::statement('CREATE VIEW shopify_sync_runs AS SELECT * FROM marketplace_sync_runs');
    }

    public function down(): void
    {
        if (DB::table('marketplace_sync_runs')->exists()) {
            throw new LogicException('Sync history contains data; use a forward compatibility migration.');
        }
        DB::statement('DROP VIEW shopify_sync_runs');
        Schema::dropIfExists('marketplace_sync_results');
        DB::statement('ALTER TABLE marketplace_sync_runs DROP CONSTRAINT sync_scope_check');
        DB::statement('ALTER TABLE marketplace_sync_runs DROP CONSTRAINT sync_run_values_check');
        Schema::table('marketplace_sync_runs', function (Blueprint $t) {
            $t->dropForeign('sync_target_listing_fk');
            $t->dropForeign('sync_target_product_fk');
            $t->dropUnique('sync_run_identity');
            $t->dropIndex('sync_dispatch_due');
            $t->dropIndex('sync_heartbeat_due');
            $t->dropColumn(['provider', 'scope', 'target_product_id', 'attempt', 'dispatch_state', 'dispatch_attempts', 'dispatch_lease_at', 'dispatched_at', 'next_dispatch_at', 'heartbeat_at', 'attempt_started_at', 'reconcile_token', 'checkpoint', 'attempt_progress', 'attempt_errors']);
            $t->foreign('target_listing_id', 'shopify_sync_runs_target_listing_id_foreign')->references('id')->on('marketplace_listings')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE marketplace_sync_runs ADD CONSTRAINT sync_run_values_check CHECK (status IN ('queued','running','completed','partial','failed') AND trigger IN ('manual','automatic'))");
        DB::statement('ALTER INDEX marketplace_one_active_run RENAME TO shopify_one_active_run');
        Schema::rename('marketplace_sync_runs', 'shopify_sync_runs');
    }
};
