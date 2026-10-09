<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AuthorController;
use App\Http\Controllers\Account\ContributionController;
use App\Http\Controllers\Account\EditorController;
// Aliased: the public sheet controller of the same name already lives here.
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\ProjectController as AccountProjectController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Account\TranslationMandateController;
use App\Http\Controllers\Account\WatchController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\FeedController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PagesController;
use App\Http\Controllers\Public\ProjectController;
use App\Http\Controllers\Public\ReportController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\SubscriptionController;
use App\Http\Controllers\Public\UnsubscribeController;
use App\Http\Middleware\SetTheme;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public surface (SPEC 6)
|--------------------------------------------------------------------------
|
| Reading, filtering and the feeds are free and accountless (SPEC 12).
|
| Everything a reader is served carries its language in its address
| (SPEC 6.5). Nine interface translations behind one address were
| reachable by no search engine, and a link shared in Spanish opened in
| whatever language the recipient's browser asked for. The prefix holds
| for French too: an aiguillage that names every language and excepts
| none is the one nobody has to remember.
|
| Machines are the exception, and on purpose: the feeds carry their own
| locale parameter, the map and the crawler instructions have no
| language at all.
|
*/

// The bare root names no language, so it picks one and says so: 302,
// because the answer depends on who asks (SPEC 6.5). It is also the
// x-default of the whole site.
Route::get('/', [HomeController::class, 'root'])->name('root');

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

Route::prefix('{locale}')
    ->whereIn('locale', (array) config('dolinews.locales', ['fr']))
    ->group(function (): void {
        Route::get('/', [HomeController::class, 'index'])->name('home');
        Route::get('/revue', [HomeController::class, 'review'])->name('review.info');
        Route::get('/articles/{article}', [ArticleController::class, 'show'])
            ->whereNumber('article')
            ->name('articles.show');
        Route::get('/projets/{slug}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/editeurs/{slug}', [ProjectController::class, 'editor'])->name('editors.show');

        // Reporting a published content to the moderation team (SPEC 9.9).
        // No account: the reader who spots a content validated too fast is
        // rarely one of the few who hold one, and the operator is the editor of
        // the validated contents (SPEC 9.7), so being reachable is part of the
        // job. The write is bounded by origin, the read is not: a form nobody
        // can open helps nobody.
        Route::get('/signaler/article/{article}', [ReportController::class, 'article'])
            ->whereNumber('article')->name('reports.article');
        Route::get('/signaler/projet/{slug}', [ReportController::class, 'project'])->name('reports.project');
        Route::middleware('throttle:report')->group(function (): void {
            Route::post('/signaler/article/{article}', [ReportController::class, 'storeArticle'])
                ->whereNumber('article')->name('reports.article.store');
            Route::post('/signaler/projet/{slug}', [ReportController::class, 'storeProject'])
                ->name('reports.project.store');
        });

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

        // Showing a section of the feed on a third-party site (SPEC 6.4):
        // the WordPress extension of the toolbox and the generic feeds.
        // Reading is free and accountless, so this page needs no token
        // and addresses a different reader than the API documentation.
        Route::get('/integrations', [PagesController::class, 'integrations'])->name('pages.integrations');

        // Leaving the subscription mails from the mail itself (SPEC 6.4): the
        // token is the credential, like the personal feed below. The POST is
        // also the RFC 8058 one-click endpoint, hence its CSRF exemption in
        // bootstrap/app.php. Prefixed like the rest of what a reader is
        // served: the mail knows the language its reader signed up in, and
        // the page that confirms the departure has no reason to guess it
        // again.
        // Subscribing with an address and nothing else (SPEC 6.4). The
        // reader the service exists for will not create an account to
        // hear about a security fix, so they never meet one: the sheet
        // takes their address, the mail takes their click, and the
        // preferences open by token like the pages below. Bounded
        // because the form mails an address a stranger typed.
        Route::middleware('throttle:subscribe')->group(function (): void {
            Route::post('/abonnement/projet/{slug}', [SubscriptionController::class, 'storeProject'])
                ->name('subscribe.project');
            Route::post('/abonnement/editeur/{slug}', [SubscriptionController::class, 'storeEditor'])
                ->name('subscribe.editor');
            Route::post('/preferences', [SubscriptionController::class, 'storePreferencesRequest'])
                ->name('subscriptions.preferences.send');
        });

        Route::get('/preferences', [SubscriptionController::class, 'preferencesRequest'])
            ->name('subscriptions.preferences.request');

        Route::middleware('throttle:auth')->group(function (): void {
            Route::get('/abonnement/{token}', [SubscriptionController::class, 'confirm'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('subscribe.confirm');
            Route::post('/abonnement/{token}', [SubscriptionController::class, 'storeConfirmation'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('subscribe.confirm.store');

            Route::get('/preferences/{token}', [SubscriptionController::class, 'preferences'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('subscriptions.preferences');
            Route::post('/preferences/{token}', [SubscriptionController::class, 'updatePreferences'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('subscriptions.preferences.update');
            Route::post('/preferences/{token}/abonnements', [SubscriptionController::class, 'destroyWatch'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('subscriptions.preferences.watch');
        });

        Route::middleware('throttle:auth')->group(function (): void {
            Route::get('/desabonnement/{token}', [UnsubscribeController::class, 'show'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('unsubscribe.show');
            Route::post('/desabonnement/{token}', [UnsubscribeController::class, 'store'])
                ->where('token', '[a-zA-Z0-9]{32}')
                ->name('unsubscribe.store');
        });
    });

// Addresses issued before the language moved into the path: kept as
// permanent redirects because the API and the publishing scripts have
// handed some of them out, and a dead link is a reader lost for good.
foreach ([
    '/revue' => 'review.info',
    '/engagements' => 'pages.commitments',
    '/regles' => 'pages.rules',
    '/donnees' => 'pages.data',
    '/mentions' => 'pages.legal',
    '/guide-editeur' => 'pages.editor-guide',
    '/documentation-api' => 'pages.api',
] as $legacy => $name) {
    Route::get($legacy, fn () => redirect()->route($name, [], 301));
}

Route::get('/articles/{article}', fn (string $article) => redirect()
    ->route('articles.show', ['article' => $article], 301))
    ->whereNumber('article');
Route::get('/projets/{slug}', fn (string $slug) => redirect()
    ->route('projects.show', ['slug' => $slug], 301));
Route::get('/editeurs/{slug}', fn (string $slug) => redirect()
    ->route('editors.show', ['slug' => $slug], 301));

// Discoverability: the crawler instructions and the map of what is
// published. Both are served by the application, the first because it
// has to name the second by its absolute address, the second because it
// is built from the feed itself.
Route::get('/robots.txt', [PagesController::class, 'robots'])->name('pages.robots');

// The WordPress extension, downloaded as the single file it is. No
// language segment: a file has no language, like the feeds and the map.
// And no .php in the address either - the scanner trap bans a client
// asking four times for such a path (config/honeypot.php), which is no
// way to treat an editor fetching the file twice.
Route::get('/integrations/dolinews-feed', [PagesController::class, 'wordpressPlugin'])
    ->name('pages.wordpress-plugin');

// Where a vulnerability of the service itself is reported (RFC 9116).
// No language segment: what machines read has none (SPEC 6.5).
Route::get('/.well-known/security.txt', [PagesController::class, 'securityTxt'])
    ->name('pages.security-txt');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-{section}.xml', [SitemapController::class, 'section'])
    ->where('section', 'pages|projects|editors|articles-[0-9]+')
    ->name('sitemap.section');

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

Route::middleware(['auth', 'active', 'password.changed', 'verified'])->prefix('account')->group(function (): void {
    Route::get('/', [AccountController::class, 'show'])->name('account.show');
    Route::get('/password', [PasswordController::class, 'edit'])->name('account.password');
    Route::post('/password', [PasswordController::class, 'update'])->name('account.password.update');
    Route::post('/', [AccountController::class, 'update'])->name('account.update');
    Route::post('/email', [AccountController::class, 'updateEmail'])->name('account.email');
    // Moderators only, enforced in the controller: it is the review
    // circuit's mail targeting, not a reader preference (SPEC 5.1).
    Route::post('/review-locales', [AccountController::class, 'updateReviewLocales'])->name('account.review-locales');
    Route::post('/feed-token', [AccountController::class, 'issueFeedToken'])->name('account.feed-token');
    Route::post('/feed-token/regenerate', [AccountController::class, 'regenerateFeedToken'])->name('account.feed-token.regenerate');

    Route::get('/contribute', [ContributionController::class, 'show'])->name('account.contribute');

    // Bounded: the first of these mails a code to a third party address
    // read from the public committer index (SPEC 3.2).
    Route::middleware('throttle:qualify')->group(function (): void {
        Route::post('/contribute/start', [ContributionController::class, 'start'])->name('account.contribute.start');
        Route::post('/contribute/verify-code', [ContributionController::class, 'verifyCode'])->name('account.contribute.code');
        Route::post('/contribute/verify-gpg', [ContributionController::class, 'verifyGpg'])->name('account.contribute.gpg');
        Route::post('/contribute/manual', [ContributionController::class, 'requestManual'])->name('account.contribute.manual');
    });

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
    // Translating is open to the editor and to whoever it mandated
    // (SPEC 5.6), hence a screen of its own: the mandated translator has
    // no business on the edit form of an article it does not own.
    Route::get('/articles/{article}/translations/new', [AuthorController::class, 'createTranslation'])
        ->whereNumber('article')->name('account.articles.translations.create');
    Route::post('/articles/{article}/translations', [AuthorController::class, 'storeTranslation'])
        ->whereNumber('article')->name('account.articles.translations');
    Route::post('/articles/{article}/translations/auto', [AuthorController::class, 'storeAutomaticTranslation'])
        ->whereNumber('article')->name('account.articles.translations.auto');

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
    // One sheet, one language, one button (SPEC 5.7): the click stands
    // for the editor's consent, as it does on an announcement.
    Route::post('/projects/{project}/translations/auto', [AccountProjectController::class, 'storeAutomaticTranslation'])
        ->whereNumber('project')->name('account.projects.translations.auto');

    // Translations (SPEC 5.6/5.7). An entry page naming the two ways an
    // announcement gets translated, then one page each: they add up,
    // they are never a choice between them.
    Route::get('/translations', [TranslationMandateController::class, 'index'])->name('account.translations');

    Route::get('/translations/mandats', [TranslationMandateController::class, 'mandates'])
        ->name('account.translations.mandates');
    Route::post('/translations/mandats', [TranslationMandateController::class, 'store'])
        ->name('account.translations.store');
    Route::delete('/translations/mandats/{mandateId}', [TranslationMandateController::class, 'destroy'])
        ->whereNumber('mandateId')->name('account.translations.destroy');

    Route::get('/translations/automatique', [TranslationMandateController::class, 'automatic'])
        ->name('account.translations.automatic');
    Route::post('/translations/automatique', [TranslationMandateController::class, 'updateAutoTranslation'])
        ->name('account.translations.auto');
    Route::post('/translations/automatique/langues', [TranslationMandateController::class, 'updateLocales'])
        ->name('account.translations.locales');
    Route::post('/translations/automatique/cle', [TranslationMandateController::class, 'updateKey'])
        ->name('account.translations.key');

    Route::get('/tokens', [TokenController::class, 'index'])->name('account.tokens');
    Route::post('/tokens', [TokenController::class, 'store'])->name('account.tokens.store');
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])
        ->whereNumber('id')->name('account.tokens.destroy');

    Route::post('/editors', [EditorController::class, 'store'])->name('account.editors.store');
    Route::post('/editors/{editorId}/members', [EditorController::class, 'attachMember'])
        ->whereNumber('editorId')->name('account.editors.members');
});

// Watch toggles are posted from the public pages by logged-in readers.
Route::middleware(['auth', 'active', 'password.changed', 'verified'])->group(function (): void {
    Route::post('/watch/project/{projectId}', [WatchController::class, 'toggleProject'])
        ->whereNumber('projectId')->name('watch.project');
    Route::post('/watch/editor/{editorId}', [WatchController::class, 'toggleEditor'])
        ->whereNumber('editorId')->name('watch.editor');
});
