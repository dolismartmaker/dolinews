<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Review\ReviewStats;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The public feed home page (SPEC 6): the dated list, filterable by
 * editor, project, Dolibarr major version, focus, language and
 * maturity.
 *
 * Non-stable maturities are excluded by default, one checkbox includes
 * them (SPEC 6.2). The version filter wording targets announcements,
 * never modules: the service says which announcements concern a
 * version, it never claims the state of a module (D1).
 */
class HomeController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly FeedService $feeds,
        private readonly ReviewStats $reviewStats,
    ) {}

    /**
     * The filtered feed.
     */
    public function index(Request $request): View
    {
        $filters = $this->filtersFrom($request);

        /** @var LengthAwarePaginator<int, Article> $articles */
        $articles = $this->feeds
            ->publicQuery($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('public.home', [
            'articles' => $articles,
            'filters' => $filters,
            'includeUnstable' => ($filters['maturities'] ?? null) !== null,
            'focusList' => Focus::cases(),
            'maturityList' => Maturity::cases(),
            'dolibarrMajors' => $this->dolibarrMajors(),
            'medianSeconds' => $this->reviewStats->observedMedianSeconds(),
            'oldestPendingDays' => $this->reviewStats->oldestPendingAgeDays(),
        ]);
    }

    /**
     * The About/transparency page: how the review works, the observed
     * delay (never a promise, SPEC 5.1) and the oldest pending age.
     */
    public function review(ReviewStats $stats): View
    {
        return view('public.review', [
            'medianSeconds' => $stats->observedMedianSeconds(),
            'oldestPendingDays' => $stats->oldestPendingAgeDays(),
            'pendingCount' => $stats->pendingCount(),
        ]);
    }

    /**
     * Parse and validate the query filters.
     *
     * @return array{editor?: string|null, project?: string|null, dolibarr?: int|null, focus?: string|null, locale?: string|null, maturities?: list<string>|null}
     */
    private function filtersFrom(Request $request): array
    {
        $maturities = null;

        if ($request->boolean('all_maturities')) {
            // Explicit opt-in (SPEC 6.2): every maturity.
            $maturities = array_map(
                static fn (Maturity $maturity): string => $maturity->value,
                Maturity::cases(),
            );
        } elseif ($request->filled('maturity')) {
            $requested = (array) $request->input('maturity');
            $valid = array_values(array_filter(
                array_map('strval', $requested),
                static fn (string $value): bool => Maturity::tryFrom($value) !== null,
            ));
            $maturities = $valid === [] ? null : $valid;
        }

        return [
            'editor' => $request->filled('editor') ? (string) $request->string('editor') : null,
            'project' => $request->filled('project') ? (string) $request->string('project') : null,
            'dolibarr' => $request->filled('dolibarr') ? (int) $request->input('dolibarr') : null,
            'focus' => $request->filled('focus') && Focus::tryFrom((string) $request->string('focus')) !== null
                ? (string) $request->string('focus')
                : null,
            'locale' => $request->filled('locale') ? (string) $request->string('locale') : null,
            'maturities' => $maturities,
        ];
    }

    /**
     * Known Dolibarr majors offered as filter choices: the last eight
     * released majors, newest first. Dolibarr ships one major per year
     * since 2004, hence the simple arithmetic.
     *
     * @return list<int>
     */
    private function dolibarrMajors(): array
    {
        $newest = now()->year - 2004;

        return array_reverse(range($newest - 7, $newest));
    }
}
