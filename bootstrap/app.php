<?php

use App\Http\Middleware\NormalizeMoneyInputs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: ['api/webhooks/whatsapp', 'api/webhooks/whatsapp-qr']);
        $middleware->web(append: [\App\Http\Middleware\AuthenticationBoundary::class, NormalizeMoneyInputs::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route($request->is('portal/*', 'api/portal/*') ? 'portal.login' : 'login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()->crmHomeUrl());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['secret', 'access_token', 'app_secret', 'verify_token', 'pin', 'code', 'recovery_code', 'token', 'password_confirmation']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
