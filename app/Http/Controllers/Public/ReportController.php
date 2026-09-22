<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Reporting a published content to the moderation team (SPEC 9.9).
 *
 * No account required, and deliberately so: the review happens before
 * publication (D6), so the person best placed to see that a content
 * slipped through is a reader who arrived by a feed or a search engine,
 * in a language nobody on the team speaks. Asking them to register first
 * is asking them to leave.
 *
 * What holds the abuse back instead: a rate limit per address, a bait
 * field, and a mandatory contact address the team can write back to.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /**
     * The form, on one published article.
     */
    public function article(Article $article): View
    {
        $this->assertReportableArticle($article);

        return view('public.report', [
            'targetLabel' => $article->title,
            'targetUrl' => route('articles.show', $article),
            'action' => route('reports.article.store', $article),
            'reasons' => ReportReason::cases(),
        ]);
    }

    /**
     * File the report on an article.
     */
    public function storeArticle(Request $request, Article $article): RedirectResponse
    {
        $this->assertReportableArticle($article);

        $payload = $this->validated($request);

        if ($payload === null) {
            return $this->acknowledge($request, route('reports.article', $article));
        }

        $this->reports->reportArticle($article, $payload, $this->reporter($request));

        return $this->acknowledge($request, route('reports.article', $article));
    }

    /**
     * The form, on one project sheet: sheet impersonation is a case of
     * its own (SPEC 9.5) and shows on no article.
     */
    public function project(string $slug): View
    {
        $project = $this->findProject($slug);

        return view('public.report', [
            'targetLabel' => $project->name,
            'targetUrl' => route('projects.show', $project->slug),
            'action' => route('reports.project.store', $project->slug),
            'reasons' => ReportReason::cases(),
        ]);
    }

    /**
     * File the report on a project sheet.
     */
    public function storeProject(Request $request, string $slug): RedirectResponse
    {
        $project = $this->findProject($slug);

        $payload = $this->validated($request);

        if ($payload === null) {
            return $this->acknowledge($request, route('reports.project', $project->slug));
        }

        $this->reports->reportProject($project, $payload, $this->reporter($request));

        return $this->acknowledge($request, route('reports.project', $project->slug));
    }

    /**
     * Validate the submission, and return null when the bait field was
     * filled: a bot fills every input it finds, a browser never shows
     * this one. The refusal is silent on purpose - telling the sender
     * which field gave them away is telling them how to pass next time -
     * but it is logged, otherwise a legitimate reporter tripped up by an
     * autofill extension would vanish without a trace.
     *
     * @return array{reason: ReportReason, body: string, email: string, locale: string}|null
     */
    private function validated(Request $request): ?array
    {
        $payload = $request->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'body' => ['required', 'string', 'min:20', 'max:4000'],
            // Mandatory: a report the team cannot ask a question about is
            // a report it often cannot act on.
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($payload['website'] ?? '') !== '') {
            Log::warning('ReportController: bait field filled, report dropped', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return null;
        }

        return [
            'reason' => ReportReason::from((string) $payload['reason']),
            'body' => (string) $payload['body'],
            'email' => (string) $payload['email'],
            // The interface locale, not a form field: it is what the team
            // answers in, and a reporter does not pick it twice.
            'locale' => app()->getLocale(),
        ];
    }

    /**
     * The same answer whether the report was recorded or dropped: a bot
     * learns nothing from the response, and a reader gets the page that
     * says what happens next.
     */
    private function acknowledge(Request $request, string $url): RedirectResponse
    {
        return redirect($url)->with('reported', true);
    }

    /**
     * The account behind the report, when the reporter happened to be
     * logged in. Never required.
     */
    private function reporter(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Only a published article is reportable: a draft or an article in
     * review is not public, and an already withdrawn one no longer needs
     * reporting.
     */
    private function assertReportableArticle(Article $article): void
    {
        abort_unless(
            $article->status === ArticleStatus::PUBLISHED && $article->deleted_at === null,
            404,
        );
    }

    private function findProject(string $slug): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->where('slug', $slug)->first();

        abort_if($project === null, 404);

        return $project;
    }
}
