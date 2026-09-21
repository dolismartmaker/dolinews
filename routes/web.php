<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AuthorController;
use App\Http\Controllers\Account\ContributionController;
use App\Http\Controllers\Account\EditorController;
// Aliased: the public sheet controller of the same name already lives here.
use App\Http\Controllers\Account\ProjectController as AccountProjectController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Account\WatchController;
use App\Http\Controllers\Admin\LeaveImpersonationController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\FeedController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PagesController;
use App\Http\Controllers\Public\ProjectController;
use App\Http\Middleware\SetTheme;
use App\Livewire\Admin\ApiRequestList;
use App\Livewire\Admin\ArticleList;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\MediaList;
use App\Livewire\Admin\ModerationLogList;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\ReviewQueue;
use App\Livewire\Admin\ReviewShow;
use App\Livewire\Admin\UserList;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public surface (SPEC 6)
|--------------------------------------------------------------------------
|
| Reading, filtering and the feeds are free and accountless (SPEC 12).
| The interface is multilingual from day one (D14) through the app
| locale, French base, English alongside.
|
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Interface locale switch (D14): restricted to the offered set.
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, (array) config('dolinews.locales', ['fr']), true)) {
        session()->put('locale', $locale);
    }

    return redirect()->back();
})->name('locale.switch');
// Theme switch: the system proposes, the visitor decides. Session-backed like
// the locale, so it needs no JavaScript on pages that load none.
Route::get('/theme/{theme}', function (string $theme) {
    if (in_array($theme, SetTheme::CHOICES, true)) {
        session()->put('theme', $theme);
    }

    return redirect()->back();
})->name('theme.switch');

Route::get('/revue', [HomeController::class, 'review'])->name('review.info');
Route::get('/articles/{article}', [ArticleController::class, 'show'])
    ->whereNumber('article')
    ->name('articles.show');
Route::get('/projets/{slug}', [ProjectController::class, 'show'])->name('projects.show');
Route::get('/editeurs/{slug}', [ProjectController::class, 'editor'])->name('editors.show');

// Versioned, published launch conditions (SPEC 9.2/12).
Route::get('/engagements', [PagesController::class, 'commitments'])->name('pages.commitments');
Route::get('/regles', [PagesController::class, 'rules'])->name('pages.rules');
Route::get('/donnees', [PagesController::class, 'data'])->name('pages.data');
Route::get('/mentions', [PagesController::class, 'legal'])->name('pages.legal');

// The editor's path (SPEC 3, 5): account, contribution proof, editor,
// token, sheet, first submission.
Route::get('/guide-editeur', [PagesController::class, 'editorGuide'])->name('pages.editor-guide');

// Documentation of the public API (SPEC 5.2), rendered from the same
// OpenAPI document that /api/v1/openapi.json serves.
Route::get('/documentation-api', [PagesController::class, 'apiDocumentation'])->name('pages.api');

// Feeds (SPEC 6.4): generic RSS/JSON without an account, personal
// tokenized RSS for readers.
Route::get('/feeds.xml', [FeedController::class, 'rss'])->name('feeds.rss');
Route::get('/feeds.json', [FeedController::class, 'json'])->name('feeds.json');
Route::get('/feeds/{token}', [FeedController::class, 'personal'])
    ->where('token', '[a-zA-Z0-9]{32}')
    ->name('feeds.personal');

/*
|--------------------------------------------------------------------------
| Authentication (six screens, one shared guest layout)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:auth')->name('login.store');

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:auth')->name('register.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:auth')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:auth')->name('password.update');
});

// Session teardown needs an active session, not guest state.
Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')->name('logout');

// Email verification of fresh accounts (signed URL, built-in flow).
Route::get('/verify-email', [EmailVerificationController::class, 'notice'])
    ->middleware('auth')->name('verification.notice');
Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth', 'signed', 'throttle:auth'])
    ->whereNumber('id')
    ->name('verification.verify');
Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth', 'throttle:auth'])->name('verification.resend');

/*
|--------------------------------------------------------------------------
| Account (readers and authors)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->prefix('account')->group(function (): void {
    Route::get('/', [AccountController::class, 'show'])->name('account.show');
    Route::post('/', [AccountController::class, 'update'])->name('account.update');
    Route::post('/feed-token', [AccountController::class, 'issueFeedToken'])->name('account.feed-token');
    Route::post('/feed-token/regenerate', [AccountController::class, 'regenerateFeedToken'])->name('account.feed-token.regenerate');

    Route::get('/contribute', [ContributionController::class, 'show'])->name('account.contribute');
    Route::post('/contribute/start', [ContributionController::class, 'start'])->name('account.contribute.start');
    Route::post('/contribute/verify-code', [ContributionController::class, 'verifyCode'])->name('account.contribute.code');
    Route::post('/contribute/verify-gpg', [ContributionController::class, 'verifyGpg'])->name('account.contribute.gpg');
    Route::post('/contribute/manual', [ContributionController::class, 'requestManual'])->name('account.contribute.manual');

    Route::get('/articles', [AuthorController::class, 'index'])->name('account.articles');
    Route::get('/articles/new', [AuthorController::class, 'create'])->name('account.articles.create');
    Route::post('/articles', [AuthorController::class, 'store'])->name('account.articles.store');
    Route::get('/articles/{article}/edit', [AuthorController::class, 'edit'])
        ->whereNumber('article')->name('account.articles.edit');
    Route::match(['put', 'patch'], '/articles/{article}', [AuthorController::class, 'update'])
        ->whereNumber('article')->name('account.articles.update');
    Route::post('/articles/{article}/submit', [AuthorController::class, 'submit'])
        ->whereNumber('article')->name('account.articles.submit');
    Route::post('/articles/{article}/revisions', [AuthorController::class, 'proposeRevision'])
        ->whereNumber('article')->name('account.articles.revisions');
    Route::post('/articles/{article}/translations', [AuthorController::class, 'storeTranslation'])
        ->whereNumber('article')->name('account.articles.translations');

    // Project sheets on the web, same ground as /api/v1/projects: naming a
    // project used to require writing curl first.
    Route::get('/projects', [AccountProjectController::class, 'index'])->name('account.projects');
    Route::get('/projects/new', [AccountProjectController::class, 'create'])->name('account.projects.create');
    Route::post('/projects', [AccountProjectController::class, 'store'])->name('account.projects.store');
    Route::get('/projects/{project}/edit', [AccountProjectController::class, 'edit'])
        ->whereNumber('project')->name('account.projects.edit');
    Route::match(['put', 'patch'], '/projects/{project}', [AccountProjectController::class, 'update'])
        ->whereNumber('project')->name('account.projects.update');
    Route::post('/projects/{project}/links', [AccountProjectController::class, 'storeLink'])
        ->whereNumber('project')->name('account.projects.links');
    Route::delete('/projects/{project}/links/{linkId}', [AccountProjectController::class, 'destroyLink'])
        ->whereNumber('project')->whereNumber('linkId')->name('account.projects.links.destroy');
    Route::post('/projects/{project}/translations', [AccountProjectController::class, 'storeTranslation'])
        ->whereNumber('project')->name('account.projects.translations');

    Route::get('/tokens', [TokenController::class, 'index'])->name('account.tokens');
    Route::post('/tokens', [TokenController::class, 'store'])->name('account.tokens.store');
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])
        ->whereNumber('id')->name('account.tokens.destroy');

    Route::post('/editors', [EditorController::class, 'store'])->name('account.editors.store');
    Route::post('/editors/{editorId}/members', [EditorController::class, 'attachMember'])
        ->whereNumber('editorId')->name('account.editors.members');
});

// Watch toggles are posted from the public pages by logged-in readers.
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/watch/project/{projectId}', [WatchController::class, 'toggleProject'])
        ->whereNumber('projectId')->name('watch.project');
    Route::post('/watch/editor/{editorId}', [WatchController::class, 'toggleEditor'])
        ->whereNumber('editorId')->name('watch.editor');
});

/*
|--------------------------------------------------------------------------
| Admin back-office (socle kit, gated by the moderator/super-admin
| columns of SPEC 4.1)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function (): void {
    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::post('/logout', LogoutController::class)->name('admin.logout');
        Route::post('/impersonate/leave', LeaveImpersonationController::class)->name('admin.impersonate.leave');
    });

    Route::middleware(['web', 'auth', 'admin'])->group(function (): void {
        Route::impersonate();
        Route::get('/', Dashboard::class)->name('admin.dashboard');
        Route::get('/users', UserList::class)->name('admin.users');
        Route::get('/editors', EditorList::class)->name('admin.editors');
        Route::get('/projects', ProjectList::class)->name('admin.projects');
        Route::get('/articles', ArticleList::class)->name('admin.articles');
        Route::get('/review', ReviewQueue::class)->name('admin.review');
        Route::get('/review/{article}', ReviewShow::class)
            ->whereNumber('article')->name('admin.review.show');
        Route::get('/moderation', ModerationLogList::class)->name('admin.moderation');
        Route::get('/media', MediaList::class)->name('admin.media');
        Route::get('/api-requests', ApiRequestList::class)->name('admin.api-requests');
    });
});
