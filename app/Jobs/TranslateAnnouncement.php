<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Machine translation of one published announcement (SPEC 5.7).
 *
 * Queued, never inline: ten languages are ten calls to an engine, and a
 * moderator's acceptance must not wait for them. Idempotent, so a retry
 * costs nothing: the service only writes the versions that are missing
 * or outdated.
 */
class TranslateAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $articleId,
    ) {}

    public function handle(AutoTranslationService $translations): void
    {
        /** @var Article|null $article */
        $article = Article::query()->find($this->articleId);

        if ($article === null) {
            Log::warning('TranslateAnnouncement: announcement gone before translation', [
                'article_id' => $this->articleId,
            ]);

            return;
        }

        $translations->sync($article);
    }
}
