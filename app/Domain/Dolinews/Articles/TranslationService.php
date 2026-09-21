<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;

/**
 * Language versions of one announcement (SPEC D14, 5.2).
 *
 * A translation is a full-fledged article: it enters the same review
 * circuit, respects the (translation_group_id, locale) uniqueness, and
 * never consumes a publication token. It carries the source's revision
 * number it was written against, which is exactly what makes a stale
 * translation detectable (SPEC 5.4).
 */
class TranslationService
{
    public function __construct(
        private readonly ArticleService $articles,
        private readonly EditorService $editors,
    ) {}

    /**
     * Whether an account may translate an announcement.
     *
     * A translation is published under the source's editor identity, so
     * it is a write on that editor's behalf: its author, or a member of
     * its editor, and nobody else. The check lives here rather than in
     * the controllers so both the web and the API surfaces inherit it.
     */
    public function canTranslate(Article $source, User $author): bool
    {
        if ($source->author_user_id === $author->getKey()) {
            return true;
        }

        return $this->editors->isMember($source->editor, $author);
    }

    /**
     * Submit a translated version of an existing announcement group.
     *
     * @param  array<string, mixed>  $payload  translated fields
     *
     * @throws ArticleException when the account may not translate this
     *                          announcement, or the group already has
     *                          this locale.
     */
    public function submitTranslation(Article $source, User $author, string $locale, array $payload): Article
    {
        if (! $this->canTranslate($source, $author)) {
            throw new ArticleException(
                'Seul l\'auteur de l\'annonce ou un membre de son éditeur peut la traduire.'
            );
        }

        if (! $source->is_source) {
            // The reference of the group is the source, whatever version
            // the caller started from.
            $root = $source->sourceArticle();

            if ($root !== null) {
                $source = $root;
            }
        }

        $exists = Article::query()
            ->where('translation_group_id', $source->translation_group_id)
            ->where('locale', $locale)
            ->exists();

        if ($exists) {
            throw new ArticleException(
                'Ce groupe comporte déjà une version en "'.htmlentities($locale).'".'
            );
        }

        $translation = Article::query()->create([
            'editor_id' => $source->editor_id,
            'project_id' => $source->project_id,
            'author_user_id' => $author->getKey(),
            'type' => $source->type,
            'focus' => $source->focus?->value,
            'title' => (string) $payload['title'],
            'slug' => $this->articles->uniqueSlug(
                (string) $payload['title'],
                $source->project_id,
            ),
            'version' => $source->version,
            'summary' => (string) $payload['summary'],
            'body' => (string) $payload['body'],
            'locale' => $locale,
            'translation_group_id' => $source->translation_group_id,
            'is_source' => false,
            // Written against the source's current text: the instant the
            // source is revised, the gap between the two numbers flags
            // the translation as outdated (SPEC 5.4).
            'source_revision_number' => $source->revision_number,
            'dolibarr_min' => $source->dolibarr_min,
            'dolibarr_max' => $source->dolibarr_max,
            'maturity' => $source->maturity,
            'compat_status' => $source->compat_status,
            'status' => ArticleStatus::DRAFT,
            'revision_number' => 0,
        ]);

        return $translation;
    }

    /**
     * The language versions of an announcement group, published ones
     * only, this article excluded.
     *
     * @return array<int, Article>
     */
    public function publishedSiblings(Article $article): array
    {
        return $article->translations()
            ->where('status', ArticleStatus::PUBLISHED->value)
            ->where('id', '!=', $article->getKey())
            ->whereNull('deleted_at')
            ->get()
            ->all();
    }
}
