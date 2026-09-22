<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;

/**
 * Shared test factory: one article shape used by every feature suite.
 */
class Factory
{
    /**
     * A fresh draft by the given author, under a dedicated editor.
     */
    public static function article(User $author, array $overrides = []): Article
    {
        $editor = self::editorFor($author);

        return app(ArticleService::class)->createDraft($author, $editor, array_merge([
            'type' => 'release',
            'title' => 'Module XY 2.1',
            'summary' => 'Correctif de securite et compatibilite v22.',
            'body' => '## Details',
            'locale' => 'fr_FR',
            'focus' => 'security',
            'maturity' => 'stable',
            'compat_status' => 'tested',
        ], $overrides));
    }

    /**
     * An editor owned by the author, shared across calls per author.
     */
    public static function editorFor(User $author): Editor
    {
        $existing = $author->editors()->first();

        if ($existing !== null) {
            return $existing;
        }

        return (new EditorService)->create($author, [
            'name' => 'Editeur '.substr(uniqid(), -5),
            'contact_email' => 'editeur-'.substr(uniqid(), -5).'@editeur.test',
        ]);
    }

    /**
     * A published article: draft -> submission -> quorum of three.
     */
    public static function publishedArticle(User $author, array $overrides = []): Article
    {
        $article = self::article($author, $overrides);
        app(ArticleService::class)->submit($article, $author);

        $review = app(ReviewService::class);

        foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
            $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
        }

        return $article->refresh();
    }

    /**
     * A published language version of an existing announcement: the same
     * review circuit, the same quorum (SPEC 5.2, D14).
     *
     * @param  array<string, mixed>  $overrides  translated fields
     */
    public static function publishedTranslation(
        User $author,
        Article $source,
        string $locale,
        array $overrides = [],
    ): Article {
        $translation = app(TranslationService::class)->submitTranslation($source, $author, $locale, array_merge([
            'title' => 'Module XY 2.1 ('.$locale.')',
            'summary' => 'Resume traduit.',
            'body' => '## Details',
        ], $overrides));

        app(ArticleService::class)->submit($translation, $author);

        $review = app(ReviewService::class);

        foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
            $review->postMessage($translation, $moderator, 'accord', ReviewDecision::ACCEPTED);
        }

        return $translation->refresh();
    }

    /**
     * A contributor account with an active manual proof, and its editor.
     *
     * @return array{0: User, 1: Editor}
     */
    public static function contributorWithEditor(): array
    {
        $user = User::factory()->create();
        app(ContributorVerificationService::class)
            ->grantManual($user, 'contrib-'.substr(uniqid(), -6).'@example.com');

        return [$user, self::editorFor($user)];
    }

    /**
     * A contributor account with an active manual proof, and no editor:
     * the state an account is in right after its proof is accepted.
     */
    public static function contributorWithoutEditor(): User
    {
        $user = User::factory()->create();
        app(ContributorVerificationService::class)
            ->grantManual($user, 'contrib-'.substr(uniqid(), -6).'@example.com');

        return $user;
    }

    /**
     * A Sanctum personal access token for the user.
     */
    public static function apiToken(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
