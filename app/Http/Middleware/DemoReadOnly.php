<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DemoReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->is_demo_access && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true), 403,
            'Демонстрационный режим: изменение данных и внешние операции недоступны.');

        return $next($request);
    }
}
