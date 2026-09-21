<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Livewire\Admin\Commands\SwitchBack as SwitchBackCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST endpoint for leaving a live impersonation (lab404/laravel-impersonate).
 *
 * Thin controller (S15): validates the session guard, delegates to the
 * SwitchBack command, and lets its RedirectResponse flow back to the caller.
 * Mounted by routes/web.php as admin.impersonate.leave so the admin layout
 * banner can submit a CSRF-protected POST form.
 */
class LeaveImpersonationController extends Controller
{
    /**
     * End the current impersonation.
     */
    public function __invoke(Request $request, SwitchBackCommand $command): RedirectResponse
    {
        return $command->leave();
    }
}
