<?php

use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\MarketplaceAccountController;
use App\Http\Controllers\Api\V1\MarketplaceInventoryController;
use App\Http\Controllers\Api\V1\MarketplaceListingController;
use App\Http\Controllers\Api\V1\MarketplaceSyncController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ShopifyController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockOperationController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseStockController;
use App\Http\Middleware\DemoReadOnly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', DemoReadOnly::class, 'throttle:api-read'])->group(function (): void {
    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::post('shopify/connections', [ShopifyController::class, 'store']);
    Route::prefix('shopify/connections/{marketplaceAccount}')->controller(ShopifyController::class)->group(function () {
        Route::get('', 'show');
        Route::patch('', 'update');
        Route::post('check', 'check');
        Route::post('sync', 'sync');
        Route::get('products', 'products');
        Route::get('products/{listing}', 'product');
        Route::post('products/{listing}/refresh', 'refreshProduct');
        Route::patch('products/{listing}/mapping', 'map');
        Route::get('inventory', 'inventory');
        Route::get('locations', 'locations');
        Route::get('runs', 'runs');
        Route::get('runs/{run}', 'run');
    });
    Route::get('onboarding', [OnboardingController::class, 'index']);
    Route::get('onboarding/checklist', [OnboardingController::class, 'checklist']);
    Route::patch('onboarding/preferences', [OnboardingController::class, 'preferences']);
    Route::patch('onboarding/tours/{tour}', [OnboardingController::class, 'update'])->where('tour', '[a-zA-Z0-9_-]{1,80}');
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::apiResource('suppliers', SupplierController::class)->except('update');
    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->parameters(['purchase-orders' => 'purchaseOrder'])->only(['index', 'store', 'show']);
    Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
    Route::post('purchase-orders/{purchaseOrder}/order', [PurchaseOrderController::class, 'order']);
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::apiResource('goods-receipts', GoodsReceiptController::class)->parameters(['goods-receipts' => 'goodsReceipt'])->only(['index', 'store', 'show']);
    Route::patch('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update']);
    Route::post('goods-receipts/{goodsReceipt}/confirm', [GoodsReceiptController::class, 'confirm']);
    Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel']);
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'show']);
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update']);
    Route::get('warehouse-stocks/summary', [WarehouseStockController::class, 'summary']);
    Route::get('warehouse-stocks', [WarehouseStockController::class, 'index']);
    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::post('stock-operations', [StockOperationController::class, 'store']);
    Route::get('auth/me', [SessionController::class, 'me']);
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::patch('organizations/active', [OrganizationController::class, 'select']);
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('catalog/{kind}/{id}', [CatalogController::class, 'show'])->whereNumber('id');
    Route::get('products/export', [ExportController::class, 'products'])->middleware('throttle:exports');
    Route::get('stocks/export', [ExportController::class, 'stocks'])->middleware('throttle:exports');
    Route::apiResource('products', ProductController::class)->except('update');
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::post('marketplace-accounts/{marketplaceAccount}/sync', [MarketplaceSyncController::class, 'sync']);
    Route::get('marketplace-accounts/{marketplaceAccount}/sync-runs', [MarketplaceSyncController::class, 'history']);
    Route::get('marketplace-accounts/{marketplaceAccount}/sync-runs/{run}', [MarketplaceSyncController::class, 'show']);
    Route::get('marketplace-accounts/{marketplaceAccount}/sync-status', [MarketplaceSyncController::class, 'status']);
    Route::post('marketplace-listings/{marketplaceListing}/refresh', [MarketplaceSyncController::class, 'refresh']);
    Route::post('marketplace-connections/check', [MarketplaceAccountController::class, 'probe'])->middleware('throttle:10,1');
    Route::get('marketplace-providers', [MarketplaceAccountController::class, 'providers']);
    Route::post('marketplace-accounts/{marketplaceAccount}/check', [MarketplaceAccountController::class, 'check']);
    Route::post('marketplace-accounts/{marketplaceAccount}/disconnect', [MarketplaceAccountController::class, 'disconnect']);
    Route::patch('marketplace-listings/{marketplaceListing}/mapping', [MarketplaceListingController::class, 'mapping']);
    Route::post('marketplace-listings/{marketplaceListing}/local-product', [MarketplaceListingController::class, 'createLocalProduct']);
    Route::apiResource('marketplace-accounts', MarketplaceAccountController::class)->parameters(['marketplace-accounts' => 'marketplaceAccount'])->except('update');
    Route::patch('marketplace-accounts/{marketplaceAccount}', [MarketplaceAccountController::class, 'update']);
    Route::apiResource('marketplace-listings', MarketplaceListingController::class)->parameters(['marketplace-listings' => 'marketplaceListing'])->except('update');
    Route::patch('marketplace-listings/{marketplaceListing}', [MarketplaceListingController::class, 'update']);
    Route::get('marketplace-accounts/{marketplaceAccount}/locations', [MarketplaceInventoryController::class, 'locations']);
    Route::get('marketplace-accounts/{marketplaceAccount}/inventory', [MarketplaceInventoryController::class, 'inventory']);
    Route::patch('marketplace-locations/{marketplaceLocation}/mapping', [MarketplaceInventoryController::class, 'mapping']);
    Route::get('marketplace-inventory', [MarketplaceInventoryController::class, 'index']);
    Route::get('stocks', StockController::class);
});

// Technical records only; no marketplace or customer data is exposed.
Route::get('/system-checks', function () {
    return response()->json([
        'data' => DB::table('system_checks')->orderBy('key')->get(['key', 'value']),
    ]);
});
