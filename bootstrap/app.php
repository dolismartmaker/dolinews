<?php

declare(strict_types=1);

use App\Core\Enums\ApiErrorCode;
use App\Core\Exceptions\ApiException;
use App\Core\Http\Middleware\AuthenticateApi;
use App\Core\Http\Middleware\CacheHeadersMiddleware;
use App\Core\Http\Middleware\EnsurePasswordIsChanged;
use App\Core\Http\Middleware\EnsureUserIsActive;
use App\Core\Http\Middleware\EnsureUserIsAdmin;
use App\Core\Http\Middleware\LogApiRequest;
use App\Core\Http\Middleware\RequestIdMiddleware;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Http\ErrorLocale;
use App\Http\Middleware\HoneypotGuard;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTheme;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // One-click unsubscribe (RFC 8058): a mail client POSTs to the
        // header's URL on its own, with no session and therefore no CSRF
        // token. The 32-character token in the path is what authorises
        // the act, and the act only ever stops mails.
        // The language of the reader leads the path since SPEC 6.5.
        $middleware->validateCsrfTokens(except: ['*/desabonnement/*']);

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
                    ->with('status', __('Session expirée, veuillez réessayer.'));
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

        // An address no route serves: the JSON envelope on the API, the
        // service's own page on the web. Laravel's bare "Not Found" is
        // an English page with no navigation, served by a site
        // translated into ten languages - the reader who mistyped an
        // announcement number is left with the back button.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => ApiErrorCode::NOT_FOUND->value,
                    'message' => ApiErrorCode::NOT_FOUND->message(),
                ], 404);
            }

            ErrorLocale::apply($request);

            return response()->view('errors.404', [], 404);
        });

        // Anything else on the API surface, 500 included. Without this,
        // a client parsing {error, message} receives Laravel's HTML
        // error page and cannot tell a fault from a refusal: it is the
        // same gap the rate limiter had before its own ->response().
        // The envelope stays the contract in debug too, the exception
        // being named in the detail rather than replacing the body.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // An exception already carrying its answer is left alone:
            // this is how the rate limiter delivers the RATE_LIMITED
            // envelope posted in AppServiceProvider, and catching it
            // here would rewrite every refusal as a fault.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = match ($status) {
                403 => ApiErrorCode::FORBIDDEN,
                404 => ApiErrorCode::NOT_FOUND,
                429 => ApiErrorCode::RATE_LIMITED,
                default => ApiErrorCode::INTERNAL,
            };

            $payload = [
                'error' => $code->value,
                'message' => $code->message(),
            ];

            if (config('app.debug') === true) {
                $payload['detail'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }

            return response()->json($payload, $status < 400 ? 500 : $status);
        });
    })->create();
