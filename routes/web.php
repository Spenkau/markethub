<?php

use App\Http\Controllers\Api\V1\Auth\DemoSessionController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'MarketHub', 'status' => 'ok', 'api_version' => 'v1']));
Route::get('/api/v1/auth/demo', [DemoSessionController::class, 'status'])->middleware('throttle:demo-login');
Route::post('/api/v1/auth/demo', [DemoSessionController::class, 'login'])->middleware('throttle:demo-login');

Route::post('/api/v1/auth/login', [SessionController::class, 'login'])->middleware('throttle:login');
Route::post('/api/v1/auth/logout', [SessionController::class, 'logout'])->middleware('auth:sanctum');
