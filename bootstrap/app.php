<?php

use App\Exceptions\DependentRecordsException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (DependentRecordsException $exception) => response()->json(['message' => $exception->getMessage()], 409));
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match ((string) $exception->getCode()) {
                '23505' => response()->json(['message' => 'Запись с такими уникальными значениями уже существует.'], 409),
                '23503' => response()->json(['message' => 'Операция нарушает связи данных.'], 409),
                '23514', '22003', '22P02' => response()->json(['message' => 'Недопустимое значение поля.'], 422),
                default => null,
            };
        });
    })->create();
