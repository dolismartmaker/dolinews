<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Translation\AutoTranslationException;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One language version asked for by the editor on its own announcement
 * (SPEC 5.7).
 *
 * Queued after the screen checked what it could: a language model takes
 * tens of seconds on a whole announcement, longer than a browser request
 * may be held. What can still go wrong here - the engine answering
 * nothing, the version written meanwhile - leaves the language missing
 * on the screen, and the editor may ask again.
 */
class TranslateAnnouncementOnDemand implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(
        public readonly int $articleId,
        public readonly string $locale,
    ) {
        $this->timeout = TranslateAnnouncement::secondsAllowed();
    }

    public function handle(AutoTranslationService $translations): void
    {
        /** @var Article|null $article */
        $article = Article::query()->find($this->articleId);

        if ($article === null) {
            Log::warning('TranslateAnnouncementOnDemand: announcement gone before translation', [
                'article_id' => $this->articleId,
                'locale' => $this->locale,
            ]);

            return;
        }

        try {
            $translations->translateInto($article, $this->locale);
        } catch (AutoTranslationException $e) {
            Log::warning('TranslateAnnouncementOnDemand: version not produced', [
                'article_id' => $this->articleId,
                'locale' => $this->locale,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
