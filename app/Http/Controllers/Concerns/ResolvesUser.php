<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated account for a controller action.
 *
 * Routes sit behind auth middleware, but static analysis cannot know
 * that: the guard turns the nullable session user into a non-null one
 * without lying to the type system.
 */
trait ResolvesUser
{
    /**
     * The authenticated account, or a 403 (never reachable behind the
     * auth middleware: defense in depth).
     */
    protected function requireUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
