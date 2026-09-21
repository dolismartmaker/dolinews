<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Subscriptions;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\EditorWatch;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectWatch;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Reader watches (SPEC 6.4): a reader follows projects and editors,
 * each watch carrying its own filters. A watch without filters applies
 * the site defaults; following a module for its security fixes alone is
 * the most frequent need, an unfiltered watch drowns it.
 */
class WatchService
{
    /**
     * Toggle a project watch, with optional filters.
     *
     * @param  array{focus?: list<string>|null, maturities?: list<string>|null}  $filters
     */
    public function toggleProject(User $user, Project $project, ?array $filters = null): bool
    {
        /** @var ProjectWatch|null $existing */
        $existing = ProjectWatch::query()
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        ProjectWatch::query()->create([
            'user_id' => $user->getKey(),
            'project_id' => $project->getKey(),
            'focus_filter' => $filters['focus'] ?? null,
            'maturity_filter' => $filters['maturities'] ?? null,
        ]);

        return true;
    }

    /**
     * Toggle an editor watch, with optional filters.
     *
     * @param  array{focus?: list<string>|null, maturities?: list<string>|null}  $filters
     */
    public function toggleEditor(User $user, Editor $editor, ?array $filters = null): bool
    {
        /** @var EditorWatch|null $existing */
        $existing = EditorWatch::query()
            ->where('user_id', $user->getKey())
            ->where('editor_id', $editor->getKey())
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        EditorWatch::query()->create([
            'user_id' => $user->getKey(),
            'editor_id' => $editor->getKey(),
            'focus_filter' => $filters['focus'] ?? null,
            'maturity_filter' => $filters['maturities'] ?? null,
        ]);

        return true;
    }

    /**
     * Issue the reader's personal feed token (SPEC 6.4): an RSS reader
     * cannot authenticate, the personal feed is a revocable tokenized
     * URL, nothing else is viable.
     */
    public function issueFeedToken(User $user): string
    {
        if ($user->feed_token === null) {
            $user->feed_token = Str::random(32);
            $user->save();
        }

        return (string) $user->feed_token;
    }

    /**
     * Revoke the personal feed token by regeneration: the old URL dies
     * immediately, the reader mints a fresh one.
     */
    public function regenerateFeedToken(User $user): string
    {
        $user->feed_token = Str::random(32);
        $user->save();

        return (string) $user->feed_token;
    }
}
