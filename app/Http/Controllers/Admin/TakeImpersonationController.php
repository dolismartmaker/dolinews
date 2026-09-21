<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Livewire\Admin\Commands\SwitchToUser;
use Illuminate\Http\RedirectResponse;

/**
 * POST endpoint for starting an impersonation.
 *
 * Replaces the lab404 GET macro. A GET that swaps the session is
 * forgeable from anywhere - an <img src="/admin/impersonate/take/12">
 * on a page a super admin happens to read is enough - and the CSRF
 * token does not cover GET. The checks themselves live in SwitchToUser,
 * which the back-office row action also calls.
 */
class TakeImpersonationController extends Controller
{
    public function __invoke(int $id, SwitchToUser $command): RedirectResponse
    {
        return $command->switchTo($id);
    }
}
