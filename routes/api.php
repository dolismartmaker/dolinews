<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountApiController;
use App\Http\Controllers\Api\V1\ArticleApiController;
use App\Http\Controllers\Api\V1\AttestationApiController;
use App\Http\Controllers\Api\V1\EditorApiController;
use App\Http\Controllers\Api\V1\MediaApiController;
use App\Http\Controllers\Api\V1\ProjectApiController;
use App\Http\Controllers\Api\V1\TokenApiController;
use App\Http\Controllers\Api\V1\WatchApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API, versioned under /api/v1 (SPEC D12/5.2)
|--------------------------------------------------------------------------
|
| Public does not mean anonymous: writes need an account and a personal
| token, and every write lands in the same human review circuit as the
| web surface. A token grants the right to submit, never to publish.
|
*/

Route::prefix('v1')->group(function (): void {
    // Public reads: throttle only (SPEC 6.4: consultation and feeds are
    // free and accountless).
    Route::middleware(['throttle:api-read'])->group(function (): void {
        Route::get('/articles', [ArticleApiController::class, 'index']);
        Route::get('/articles/{id}', [ArticleApiController::class, 'show'])->whereNumber('id');
        Route::get('/projects', [ProjectApiController::class, 'index']);
        Route::get('/projects/{slug}', [ProjectApiController::class, 'show']);
        Route::post('/projects', [ProjectApiController::class, 'store']);
        Route::get('/editors', [EditorApiController::class, 'index']);
        Route::get('/editors/{slug}', [EditorApiController::class, 'show']);
    });

    // Authenticated surface: Sanctum token -> account guard. Every
    // authenticated call lands in api_requests for observability
    // (SPEC 5.2), writes add the tighter throttle and cache headers.
    Route::middleware([
        'auth:sanctum',
        'api.auth',
        'api.log',
    ])->group(function (): void {
        Route::get('/profile', [AccountApiController::class, 'profile']);
        Route::post('/logout', [AccountApiController::class, 'logout']);

        Route::get('/tokens', [TokenApiController::class, 'index']);
        Route::post('/tokens', [TokenApiController::class, 'store']);
        Route::delete('/tokens/{id}', [TokenApiController::class, 'destroy'])->whereNumber('id');

        Route::get('/watches', [WatchApiController::class, 'index']);
        Route::post('/watches', [WatchApiController::class, 'store']);
        Route::post('/watches/feed-token', [WatchApiController::class, 'feedToken']);

        Route::middleware(['throttle:api-write', 'api.cache'])->group(function (): void {
            Route::post('/media', [MediaApiController::class, 'store']);
            Route::post('/articles', [ArticleApiController::class, 'store']);
            Route::patch('/articles/{id}', [ArticleApiController::class, 'update'])->whereNumber('id');
            Route::post('/articles/{id}/submit', [ArticleApiController::class, 'submit'])->whereNumber('id');
            Route::post('/articles/{id}/translations', [ArticleApiController::class, 'storeTranslation'])->whereNumber('id');
            Route::post('/articles/{id}/revisions', [ArticleApiController::class, 'storeRevision'])->whereNumber('id');
            Route::post('/projects/{slug}/links', [ProjectApiController::class, 'storeLink']);
            Route::post('/projects/{slug}/translations', [ProjectApiController::class, 'storeTranslation']);
            Route::post('/attestations', [AttestationApiController::class, 'store']);
        });
    });
});
