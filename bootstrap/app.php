<?php

declare(strict_types=1);

use App\Core\Exceptions\ApiException;
use App\Core\Http\Middleware\AuthenticateApi;
use App\Core\Http\Middleware\CacheHeadersMiddleware;
use App\Core\Http\Middleware\EnsurePasswordIsChanged;
use App\Core\Http\Middleware\EnsureUserIsActive;
use App\Core\Http\Middleware\EnsureUserIsAdmin;
use App\Core\Http\Middleware\LogApiRequest;
use App\Core\Http\Middleware\RequestIdMiddleware;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\HoneypotGuard;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTheme;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API middleware aliases (S7, minus the subscription/quota stages:
        // DoliNews is free of charge, SPEC section 12, so the pile is
        // auth -> throttle -> log -> cache headers).
        $middleware->alias([
            'api.auth' => AuthenticateApi::class,
            'api.log' => LogApiRequest::class,
            'api.cache' => CacheHeadersMiddleware::class,

            // Web admin back-office guard (S2/section 6 of the socle).
            'admin' => EnsureUserIsAdmin::class,

            // A suspension must bite on the session already open, not
            // only at the next login (SPEC 9.3).
            'active' => EnsureUserIsActive::class,

            // An account still carrying the password it was created with
            // goes nowhere else first.
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);

        // The admin group runs ['web','auth','admin']; 'auth' fires before
        // our 'admin' guard, so point Laravel's unauthenticated redirect at
        // the login screen (there is no other web login surface).
        $middleware->redirectGuestsTo(fn (): string => route('login'));

        // Request correlation id on every request/response, then the
        // honeypot scanner trap on the GLOBAL pile (a path no route
        // serves never reaches a group middleware), appended after
        // TrustProxies so the reported address is the client's.
        $middleware->append(RequestIdMiddleware::class);
        $middleware->append(HoneypotGuard::class);

        // Browser-side defence in depth on every response, the honeypot's
        // 404s included.
        $middleware->append(SecurityHeaders::class);

        // Interface locale from the session (D14).
        $middleware->web(append: SetLocale::class);

        // Light or dark theme from the session, the system deciding by
        // default.
        $middleware->web(append: SetTheme::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unauthenticated API calls get the JSON error envelope, never an
        // HTML redirect (the redirect above is for the web surface only).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'INVALID_TOKEN',
                    'message' => 'Jeton d\'authentification invalide ou expiré.',
                ], 401);
            }

            return null;
        });

        // A forged or expired CSRF token on the web surface lands on the
        // login page with the generic error flash, not a bare 419 page.
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return redirect()->route('login')
                    ->with('status', 'Session expirée, veuillez réessayer.');
            }

            return null;
        });

        // Named API error codes surface as the JSON envelope everywhere.
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => $e->code()->value,
                    'message' => $e->getMessage(),
                    'detail' => (object) $e->detail(),
                ], $e->getCode());
            }

            return null;
        });

        // Keep route model binding failures explicit for the API surface.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'NOT_FOUND',
                    'message' => 'Ressource introuvable.',
                ], 404);
            }

            return null;
        });

        // Validation failures on the API surface keep the uniform
        // envelope (S8): error code, message, detail.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'VALIDATION_FAILED',
                    'message' => 'Les données fournies sont invalides.',
                    'detail' => $e->errors(),
                ], 422);
            }

            return null;
        });
    })->create();
