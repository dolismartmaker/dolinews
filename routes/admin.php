<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LogoutController;
use App\Livewire\Admin\ApiRequestList;
use App\Livewire\Admin\ArticleList;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\MediaList;
use App\Livewire\Admin\ModerationLogList;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\ReportList;
use App\Livewire\Admin\ReviewQueue;
use App\Livewire\Admin\ReviewShow;
use App\Livewire\Admin\UserList;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin back-office (caprel/laravel-admin, gated by the moderator and
| super-admin columns of SPEC 4.1)
|--------------------------------------------------------------------------
|
| The impersonation leave route is declared by the package, under "auth"
| alone. Starting one is a Livewire action of the accounts list, never a
| route: a GET would let any third-party page trigger the session swap.
*/

Route::prefix('admin')->group(function (): void {
    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::post('/logout', LogoutController::class)->name('admin.logout');
    });

    Route::middleware(['web', 'auth', 'password.changed', 'admin'])->group(function (): void {
        Route::get('/', Dashboard::class)->name('admin.dashboard');
        Route::get('/users', UserList::class)->name('admin.users.index');
        Route::get('/editors', EditorList::class)->name('admin.editors.index');
        Route::get('/projects', ProjectList::class)->name('admin.projects.index');
        Route::get('/articles', ArticleList::class)->name('admin.articles.index');
        Route::get('/review', ReviewQueue::class)->name('admin.review.index');
        Route::get('/review/{article}', ReviewShow::class)
            ->whereNumber('article')->name('admin.review.show');
        Route::get('/reports', ReportList::class)->name('admin.reports.index');
        Route::get('/moderation', ModerationLogList::class)->name('admin.moderation.index');
        Route::get('/media', MediaList::class)->name('admin.media.index');
        Route::get('/api-requests', ApiRequestList::class)->name('admin.api-requests.index');
    });
});
