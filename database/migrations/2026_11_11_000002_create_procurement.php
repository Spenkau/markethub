<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('inn', 30)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'is_active']);
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('status', 30)->default('draft');
            $table->char('currency', 3);
            $table->decimal('total_amount', 24, 2)->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'request_id']);
            $table->foreign(['organization_id', 'supplier_id'])->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'])->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'id']);
        });
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id');
            $table->foreignId('product_id');
            $table->integer('quantity');
            $table->integer('received_quantity')->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 24, 2)->storedAs('quantity::numeric * unit_price');
            $table->unique(['organization_id', 'purchase_order_id', 'product_id'], 'purchase_item_product_unique');
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'purchase_order_id', 'id', 'product_id'], 'purchase_item_identity_unique');
            $table->foreign(['organization_id', 'purchase_order_id'])->references(['organization_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('draft');
            $table->text('comment')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'request_id']);
            $table->unique(['organization_id', 'id', 'purchase_order_id'], 'receipt_order_identity_unique');
            $table->foreign(['organization_id', 'purchase_order_id'])->references(['organization_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'id']);
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->unique(['organization_id', 'id'], 'movement_org_id_unique'));
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id');
            $table->foreignId('purchase_order_id');
            $table->foreignId('purchase_order_item_id');
            $table->foreignId('product_id');
            $table->integer('quantity');
            $table->uuid('operation_id');
            $table->foreignId('stock_movement_id')->nullable()->unique();
            $table->unique(['organization_id', 'goods_receipt_id', 'purchase_order_item_id'], 'receipt_line_unique');
            $table->unique(['organization_id', 'operation_id']);
            $table->foreign(['organization_id', 'goods_receipt_id', 'purchase_order_id'], 'receipt_line_document_fk')->references(['organization_id', 'id', 'purchase_order_id'])->on('goods_receipts')->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_order_id', 'purchase_order_item_id', 'product_id'], 'receipt_line_order_item_fk')->references(['organization_id', 'purchase_order_id', 'id', 'product_id'])->on('purchase_order_items')->restrictOnDelete();
            $table->foreign(['organization_id', 'stock_movement_id'], 'receipt_line_movement_fk')->references(['organization_id', 'id'])->on('stock_movements')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT supplier_name_check CHECK (length(trim(name)) > 0)');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_values_check CHECK (status IN ('draft','ordered','partially_received','received','cancelled') AND total_amount >= 0 AND currency IN ('RUB','USD','EUR','BYN','KZT','CNY','GBP'))");
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_item_values_check CHECK (quantity > 0 AND received_quantity BETWEEN 0 AND quantity AND unit_price >= 0)');
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT receipt_values_check CHECK ((status = 'confirmed' AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL) OR (status IN ('draft','cancelled') AND confirmed_by IS NULL AND confirmed_at IS NULL))");
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT receipt_quantity_check CHECK (quantity > 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION markethub_confirmed_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'goods_receipts' THEN
        IF OLD.status = 'confirmed' THEN RAISE EXCEPTION 'Confirmed receipts are immutable' USING ERRCODE = '23514'; END IF;
    ELSE
        IF EXISTS (SELECT 1 FROM goods_receipts WHERE id = OLD.goods_receipt_id AND status = 'confirmed') THEN
            RAISE EXCEPTION 'Confirmed receipt lines are immutable' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER confirmed_receipt_guard BEFORE UPDATE OR DELETE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION markethub_confirmed_receipt_guard();
CREATE TRIGGER confirmed_receipt_line_guard BEFORE UPDATE OR DELETE ON goods_receipt_items FOR EACH ROW EXECUTE FUNCTION markethub_confirmed_receipt_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        DB::statement('DROP FUNCTION IF EXISTS markethub_confirmed_receipt_guard()');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropUnique('movement_org_id_unique'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
