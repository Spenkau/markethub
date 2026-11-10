<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['organization_id', 'user_id']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('active_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });
        Schema::create('marketplace_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('marketplace', 20);
            $table->string('name');
            $table->string('status', 20);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'accounts_org_id_unique');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('base_price_minor');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'id'], 'products_org_id_unique');
        });
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('marketplace_account_id');
            $table->string('external_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->integer('stock_quantity');
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['product_id', 'marketplace_account_id'], 'listing_product_account_unique');
            $table->unique(['marketplace_account_id', 'external_id'], 'listing_account_external_unique');
            $table->foreign(['organization_id', 'product_id'], 'listing_product_org_fk')->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'marketplace_account_id'], 'listing_account_org_fk')->references(['organization_id', 'id'])->on('marketplace_accounts')->restrictOnDelete();
            $table->index(['organization_id', 'stock_quantity']);
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (base_price_minor BETWEEN 0 AND 999999999999)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_name_check CHECK (length(trim(name)) > 0 AND length(trim(sku)) > 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_currency_check CHECK (currency IN ('RUB','USD','EUR','BYN','KZT','CNY','GBP'))");
        DB::statement("ALTER TABLE marketplace_accounts ADD CONSTRAINT accounts_values_check CHECK (marketplace IN ('wildberries','ozon') AND status IN ('demo','active','disabled') AND length(trim(name)) > 0)");
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT listings_values_check CHECK (price_minor BETWEEN 0 AND 999999999999 AND stock_quantity >= 0 AND status IN ('active','inactive'))");
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT listings_currency_check CHECK (currency IN ('RUB','USD','EUR','BYN','KZT','CNY','GBP'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('products');
        Schema::dropIfExists('marketplace_accounts');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('active_organization_id'));
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
