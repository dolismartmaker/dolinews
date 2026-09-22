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
 * Non-stable maturities are excluded (SPEC 6.2), with no blanket
 * include-everything switch: an integrator who wants test versions asks
 * for the ones they want by name, through maturity[]. The version filter
 * wording targets announcements, never modules: the service says which
 * announcements concern a version, it never claims the state of a
 * module (D1).
 */
class HomeController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly FeedService $feeds,
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
            'focusList' => Focus::cases(),
            'maturityList' => Maturity::cases(),
            'dolibarrMajors' => $this->dolibarrMajors(),
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

        // Named maturities only (SPEC 6.2): asking for beta is a choice,
        // "everything at once" was a switch nobody could read.
        if ($request->filled('maturity')) {
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
            // The feed speaks the language of the interface, and that
            // choice is made once, in the header switch: a second
            // language control among the filters would only let the two
            // disagree. An announcement with no version in that language
            // is not shown here; it stays published, reachable by its
            // own page, its editor page and the feeds (SPEC 6.1).
            'locale' => app()->getLocale(),
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
