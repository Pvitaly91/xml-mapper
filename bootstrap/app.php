<?php

use App\Http\Middleware\SecureHeadersMiddleware;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsureAdminPermissionMiddleware;
use App\Http\Middleware\ResolveAdminShopContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.permission' => EnsureAdminPermissionMiddleware::class,
            'admin.shop.context' => ResolveAdminShopContextMiddleware::class,
        ]);

        $middleware->web(append: [
            CorrelationIdMiddleware::class,
            SecureHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'api_token',
            'target_value',
            'webhook_url',
            'target_secret',
            'headers',
        ]);
    })->create();
