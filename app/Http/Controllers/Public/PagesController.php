<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Static public pages: the versioned commitments (SPEC 12), the
 * numbered usage rules (SPEC 9.2) and the personal-data summary
 * (SPEC 9.8). All three are launch conditions: published and versioned
 * before the service opens.
 */
class PagesController extends Controller
{
    /**
     * The public commitments, versioned (SPEC 12).
     */
    public function commitments(): View
    {
        return view('public.commitments', [
            'version' => config('dolinews.commitments_version', '1.0'),
        ]);
    }

    /**
     * The numbered usage rules, versioned (SPEC 9.2): a sanction can
     * only rely on a numbered rule that existed at the time of the
     * facts, never retroactively.
     */
    public function rules(): View
    {
        return view('public.rules', [
            'version' => config('dolinews.rules_version', '1.0'),
        ]);
    }

    /**
     * Personal data summary (SPEC 9.8): inventory and rights.
     */
    public function data(): View
    {
        return view('public.data');
    }

    /**
     * Legal notices.
     */
    public function legal(): View
    {
        return view('public.legal');
    }
}
