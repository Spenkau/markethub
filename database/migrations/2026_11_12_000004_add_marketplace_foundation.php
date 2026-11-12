<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', fn (Blueprint $t) => $t->jsonb('configuration')->nullable());
        Schema::create('marketplace_credentials', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id')->unique();
            $t->string('auth_type', 40);
            $t->text('payload');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign(['organization_id', 'marketplace_account_id'])->references(['organization_id', 'id'])->on('marketplace_accounts')->restrictOnDelete();
        });
        Schema::create('marketplace_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('marketplace_account_id');
            $t->string('external_id');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('status', 40);
            $t->jsonb('images');
            $t->jsonb('source_data');
            $t->timestampTz('source_created_at')->nullable();
            $t->timestampTz('source_updated_at')->nullable();
            $t->timestampTz('source_synced_at')->nullable();
            $t->timestampsTz();
            $t->unique(['marketplace_account_id', 'external_id']);
            $t->unique(['organization_id', 'marketplace_account_id', 'id'], 'marketplace_parent_identity');
            $t->foreign(['organization_id', 'marketplace_account_id'])->references(['organization_id', 'id'])->on('marketplace_accounts')->restrictOnDelete();
        });
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->unsignedBigInteger('marketplace_product_id')->nullable();
            $t->string('origin', 20)->default('local');
            $t->string('variant_title')->nullable();
            $t->string('barcode')->nullable();
            $t->timestampTz('source_created_at')->nullable();
            $t->timestampTz('source_updated_at')->nullable();
            $t->unique(['organization_id', 'marketplace_account_id', 'id'], 'listing_account_identity');
            $t->foreign(['organization_id', 'marketplace_account_id', 'marketplace_product_id'], 'listing_parent_identity_fk')->references(['organization_id', 'marketplace_account_id', 'id'])->on('marketplace_products')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT listing_origin_check CHECK (origin IN ('local','demo','imported'))");
        // New providers are validated by the registry, not a Shopify-only database enum.
        DB::statement('ALTER TABLE marketplace_accounts DROP CONSTRAINT accounts_values_check');
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT accounts_values_check CHECK (marketplace ~ '^[a-z][a-z0-9_]{0,19}$' AND status IN ('demo','active','disabled') AND length(trim(name)) > 0)");
    }

    public function down(): void
    {
        // Never silently discard imported parents or encrypted credentials on rollback.
        if (DB::table('marketplace_products')->exists() || DB::table('marketplace_credentials')->exists()) {
            throw new LogicException('Marketplace foundation contains data; use a forward compatibility migration.');
        }
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropForeign('listing_parent_identity_fk');
            $t->dropUnique('listing_account_identity');
            $t->dropColumn(['marketplace_product_id', 'origin', 'variant_title', 'barcode', 'source_created_at', 'source_updated_at']);
        });
        Schema::dropIfExists('marketplace_products');
        Schema::dropIfExists('marketplace_credentials');
        Schema::table('marketplace_accounts', fn (Blueprint $t) => $t->dropColumn('configuration'));
        DB::statement('ALTER TABLE marketplace_accounts DROP CONSTRAINT accounts_values_check');
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT accounts_values_check CHECK (marketplace IN ('wildberries','ozon','shopify') AND status IN ('demo','active','disabled') AND length(trim(name)) > 0)");
    }
};
