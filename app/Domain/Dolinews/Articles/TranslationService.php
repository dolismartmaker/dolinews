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
        private readonly TranslationMandateService $mandates,
    ) {}

    /**
     * Whether an account may translate an announcement.
     *
     * A translation is published under the source's editor identity, so
     * it is a write on that editor's behalf: its author, a member of its
     * editor, or an account the editor mandated (SPEC 5.6). The check
     * lives here rather than in the controllers so both the web and the
     * API surfaces inherit it.
     */
    public function canTranslate(Article $source, User $author, ?string $locale = null): bool
    {
        if ($this->belongsToEditor($source, $author)) {
            return true;
        }

        // A mandate is granted per locale: holding one for Spanish says
        // nothing about Greek. With no locale in hand the question is
        // only whether the account holds any mandate at all, which is
        // what the screens ask to decide whether to offer the form.
        return $this->mandates->covers(
            $source->editor,
            $author,
            $source->project_id,
            $locale,
        );
    }

    /**
     * Whether the account writes under the editor's own identity: the
     * author of the announcement, or a member of its editor.
     *
     * This is the line the review regime follows (SPEC 5.1): an editor
     * translating its own announcement publishes without review, a
     * mandated outsider goes through a reviewer.
     */
    public function belongsToEditor(Article $source, User $author): bool
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
        if (! $this->canTranslate($source, $author, $locale)) {
            throw new ArticleException(
                'Traduire cette annonce demande d\'appartenir à son éditeur, ou d\'en tenir un mandat de traduction pour cette langue.'
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
