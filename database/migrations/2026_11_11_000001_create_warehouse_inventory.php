<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('warehouse_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'warehouse_id', 'product_id'], 'warehouse_stock_pair_unique');
            $table->foreign(['organization_id', 'warehouse_id'])->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->index(['organization_id', 'quantity']);
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->string('type', 20);
            $table->integer('quantity_delta');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->text('comment')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->unique(['organization_id', 'operation_id', 'warehouse_id'], 'movement_operation_warehouse_unique');
            $table->foreign(['organization_id', 'warehouse_id'])->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->index(['organization_id', 'occurred_at', 'id']);
            $table->index(['organization_id', 'warehouse_id', 'product_id']);
        });
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouse_values_check CHECK (type IN ('own','marketplace') AND length(trim(name)) > 0 AND length(trim(code)) > 0)");
        DB::statement('ALTER TABLE warehouse_stocks ADD CONSTRAINT warehouse_quantity_check CHECK (quantity >= 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT movement_values_check CHECK (quantity_before >= 0 AND quantity_after >= 0 AND quantity_after::bigint = quantity_before::bigint + quantity_delta::bigint AND ((type = 'receipt' AND quantity_delta > 0) OR (type = 'write_off' AND quantity_delta < 0) OR (type = 'transfer' AND quantity_delta <> 0) OR type = 'inventory'))");
        DB::unprepared("CREATE OR REPLACE FUNCTION markethub_immutable_movement() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Stock movements are immutable' USING ERRCODE = '23514'; END; \$\$");
        DB::unprepared('CREATE TRIGGER immutable_stock_movements BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION markethub_immutable_movement()');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS markethub_immutable_movement()');
        Schema::dropIfExists('warehouse_stocks');
        Schema::dropIfExists('warehouses');
    }
};
