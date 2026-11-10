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

        // SQLite CHECK constraints and composite foreign keys also protect direct SQL writes.
        DB::statement("CREATE TABLE marketplace_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
            marketplace VARCHAR(20) NOT NULL CHECK (marketplace IN ('wildberries','ozon')),
            name VARCHAR(255) NOT NULL CHECK (length(trim(name)) > 0),
            status VARCHAR(20) NOT NULL CHECK (status IN ('demo','active','disabled')),
            is_demo BOOLEAN NOT NULL DEFAULT 0,
            created_at DATETIME, updated_at DATETIME,
            UNIQUE (organization_id, id)
        )");
        DB::statement("CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
            sku VARCHAR(100) NOT NULL CHECK (length(trim(sku)) > 0),
            name VARCHAR(255) NOT NULL CHECK (length(trim(name)) > 0),
            description TEXT,
            base_price_minor INTEGER NOT NULL CHECK (typeof(base_price_minor) = 'integer' AND base_price_minor BETWEEN 0 AND 999999999999),
            currency VARCHAR(3) NOT NULL CHECK (currency IN ('RUB','USD','EUR','BYN','KZT','CNY','GBP')),
            is_active BOOLEAN NOT NULL DEFAULT 1,
            created_at DATETIME, updated_at DATETIME,
            UNIQUE (organization_id, sku), UNIQUE (organization_id, id)
        )");
        DB::statement("CREATE TABLE marketplace_listings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
            product_id INTEGER NOT NULL,
            marketplace_account_id INTEGER NOT NULL,
            external_id VARCHAR(255), external_sku VARCHAR(255),
            price_minor INTEGER NOT NULL CHECK (typeof(price_minor) = 'integer' AND price_minor BETWEEN 0 AND 999999999999),
            currency VARCHAR(3) NOT NULL CHECK (currency IN ('RUB','USD','EUR','BYN','KZT','CNY','GBP')),
            stock_quantity INTEGER NOT NULL CHECK (typeof(stock_quantity) = 'integer' AND stock_quantity BETWEEN 0 AND 2147483647),
            status VARCHAR(20) NOT NULL CHECK (status IN ('active','inactive')),
            created_at DATETIME, updated_at DATETIME,
            UNIQUE (product_id, marketplace_account_id),
            UNIQUE (marketplace_account_id, external_id),
            FOREIGN KEY (organization_id, product_id) REFERENCES products(organization_id, id) ON DELETE RESTRICT,
            FOREIGN KEY (organization_id, marketplace_account_id) REFERENCES marketplace_accounts(organization_id, id) ON DELETE RESTRICT
        )");
        DB::statement('CREATE INDEX listings_organization_stock ON marketplace_listings (organization_id, stock_quantity)');
        DB::statement('CREATE INDEX accounts_organization ON marketplace_accounts (organization_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('products');
        Schema::dropIfExists('marketplace_accounts');
        Schema::dropIfExists('organization_user');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_organization_id');
        });
        Schema::dropIfExists('organizations');
    }
};
