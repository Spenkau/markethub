<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Resources\Api\V1\UserResource;
use App\Services\PublicDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoSessionController
{
    public function status(PublicDemo $demo)
    {
        return response()->json(['available' => $demo->user() !== null]);
    }

    public function login(Request $request, PublicDemo $demo): UserResource
    {
        $user = $demo->user();
        abort_unless($user, 503, 'Публичная демоверсия пока не подготовлена.');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new UserResource($user);
    }
}
