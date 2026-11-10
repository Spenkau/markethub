<?php

use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\MarketplaceAccountController;
use App\Http\Controllers\Api\V1\MarketplaceListingController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StockController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('auth/me', [SessionController::class, 'me']);
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::patch('organizations/active', [OrganizationController::class, 'select']);
    Route::apiResource('products', ProductController::class)->except('update');
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::apiResource('marketplace-accounts', MarketplaceAccountController::class)->parameters(['marketplace-accounts' => 'marketplaceAccount'])->except('update');
    Route::patch('marketplace-accounts/{marketplaceAccount}', [MarketplaceAccountController::class, 'update']);
    Route::apiResource('marketplace-listings', MarketplaceListingController::class)->parameters(['marketplace-listings' => 'marketplaceListing'])->except('update');
    Route::patch('marketplace-listings/{marketplaceListing}', [MarketplaceListingController::class, 'update']);
    Route::get('stocks', StockController::class);
});

// Technical records only; no marketplace or customer data is exposed.
Route::get('/system-checks', function () {
    return response()->json([
        'data' => DB::table('system_checks')->orderBy('key')->get(['key', 'value']),
    ]);
});
