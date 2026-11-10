<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Technical records only; no marketplace or customer data is exposed.
Route::get('/system-checks', function () {
    return response()->json([
        'data' => DB::table('system_checks')->orderBy('key')->get(['key', 'value']),
    ]);
});
