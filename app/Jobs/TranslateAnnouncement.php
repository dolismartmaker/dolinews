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
 *
 * Dispatched without a language, it only queues one job per language
 * to work on. A language model takes tens of seconds on a whole
 * announcement: nine languages in one job would outlast the deadline
 * of the job, and the worker would kill it halfway, or the queue would
 * hand it to a second run while the first is still waiting.
 */
class TranslateAnnouncement implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds left to the job beyond the engine's own deadline: the
     * writes and the publication that follow its answer.
     */
    private const MARGIN = 60;

    public int $tries = 2;

    public int $timeout;

    public function __construct(
        public readonly int $articleId,
        public readonly ?string $locale = null,
    ) {
        $this->timeout = self::secondsAllowed();
    }

    /**
     * How long a job that calls the engine once may run.
     *
     * The queue's retry_after has to stay above it, or the queue hands
     * the job to another run while this one is still waiting on the
     * engine.
     */
    public static function secondsAllowed(): int
    {
        return (int) config('dolinews.translation.timeout', 120) + self::MARGIN;
    }

    public function handle(AutoTranslationService $translations): void
    {
        /** @var Article|null $article */
        $article = Article::query()->find($this->articleId);

        if ($article === null) {
            Log::warning('TranslateAnnouncement: announcement gone before translation', [
                'article_id' => $this->articleId,
                'locale' => $this->locale,
            ]);

            return;
        }

        if ($this->locale !== null) {
            $translations->sync($article, locale: $this->locale);

            return;
        }

        $locales = $translations->pendingLocales($article);

        foreach ($locales as $locale) {
            self::dispatch($this->articleId, $locale);
        }

        if ($locales !== []) {
            Log::info('TranslateAnnouncement: one job queued per language', [
                'article_id' => $this->articleId,
                'locales' => $locales,
            ]);
        }
    }
}
