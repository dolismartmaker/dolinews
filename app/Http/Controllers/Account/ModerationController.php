<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Models\Project;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The moderation acts that concern the account (SPEC 9.4): the journal is
 * readable at least by whoever it targets, otherwise moderation drifts
 * towards arbitrariness.
 *
 * "Concern" covers the account itself, the articles it wrote, and the
 * articles, sheets and editor of every editor it belongs to: an editor's
 * member answers for what is published in its name. The moderator stays
 * unnamed - the act is the team's, and naming a volunteer to the party it
 * sanctioned exposes that person without telling the author anything it
 * needs to contest.
 */
class ModerationController extends Controller
{
    use ResolvesUser;

    public function index(Request $request): View
    {
        $user = $this->requireUser($request);
        $editorIds = $user->editors()->select('editors.id');

        $acts = ModerationLog::query()
            ->with(['article', 'project', 'editor'])
            ->where(function (Builder $concern) use ($user, $editorIds): void {
                $concern->where('user_id', $user->getKey())
                    ->orWhereIn('article_id', Article::query()
                        ->where('author_user_id', $user->getKey())
                        ->orWhereIn('editor_id', $editorIds)
                        ->select('id'))
                    ->orWhereIn('project_id', Project::query()
                        ->whereIn('editor_id', $editorIds)
                        ->select('id'))
                    ->orWhereIn('editor_id', $editorIds);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('account.moderation', ['acts' => $acts]);
    }
}
