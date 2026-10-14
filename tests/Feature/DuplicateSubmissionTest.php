<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * A version is proposed once (SPEC 5.2): a second submission of the same
 * project, version and language corrects the one still under review, and
 * is refused once that one is published.
 */
function duplicatePayload(Editor $editor, Project $project, array $overrides = []): array
{
    return array_merge([
        'editor_id' => $editor->getKey(),
        'project_id' => $project->getKey(),
        'type' => 'release',
        'focus' => 'bugfix_minor',
        'title' => 'Module XY 2.1',
        'version' => '2.1.0',
        'summary' => 'Correctif de la relance des factures.',
        'body' => 'Corps de l\'annonce.',
        'locale' => 'fr_FR',
        'submit' => true,
    ], $overrides);
}

it('updates the pending announcement instead of creating a second one', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $token = Factory::apiToken($author);

    $first = $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/articles', duplicatePayload($editor, $project, [
            'version' => 'v2.1.0',
            'summary' => 'Correctif de la relance des factures, résumé corrigé.',
        ]))
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('meta.existing_article', true);

    $article = Article::query()->sole();

    expect($article->summary)->toBe('Correctif de la relance des factures, résumé corrigé.')
        ->and($article->status)->toBe(ArticleStatus::PENDING);
});

it('keeps the accords when the same text is sent again', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $token = Factory::apiToken($author);

    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project))->assertCreated();

    $article = Article::query()->sole();
    app(ReviewService::class)->postMessage($article, User::factory()->moderator()->create(), 'accord', ReviewDecision::ACCEPTED);
    $round = $article->refresh()->submission_seq;

    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project))->assertOk();

    expect($article->refresh()->submission_seq)->toBe($round)
        ->and(app(ReviewService::class)->currentAccords($article))->toHaveCount(1);
});

it('resubmits a rejected announcement instead of doubling it', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $token = Factory::apiToken($author);

    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project))->assertCreated();

    $article = Article::query()->sole();
    app(ReviewService::class)->postMessage($article, User::factory()->moderator()->create(), 'lien trompeur', ReviewDecision::REJECTED, 'R4');

    $this->withToken($token)
        ->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['body' => 'Corps corrigé.']))
        ->assertOk();

    expect(Article::query()->count())->toBe(1)
        ->and($article->refresh()->status)->toBe(ArticleStatus::PENDING)
        ->and($article->body)->toBe('Corps corrigé.');
});

it('refuses a version already published and names the article', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $published = Factory::publishedArticle($author, ['project_id' => $project->getKey(), 'version' => '2.1.0']);

    $this->withToken(Factory::apiToken($author))
        ->postJson('/api/v1/articles', duplicatePayload($editor, $project))
        ->assertStatus(409)
        ->assertJsonPath('error', 'ALREADY_PUBLISHED')
        ->assertJsonPath('detail.article_id', $published->getKey())
        ->assertJsonStructure(['error', 'message']);

    expect(Article::query()->count())->toBe(1);
});

it('does not touch the announcement of another member of the editor', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $this->withToken(Factory::apiToken($author))->postJson('/api/v1/articles', duplicatePayload($editor, $project))->assertCreated();

    $colleague = Factory::contributorWithoutEditor();
    $editor->users()->attach($colleague->getKey(), ['role' => 'member']);

    // The guard keeps the account of the previous request within a test.
    app('auth')->forgetGuards();

    $this->withToken(Factory::apiToken($colleague))
        ->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['summary' => 'Autre résumé.']))
        ->assertStatus(409)
        ->assertJsonPath('error', 'CONFLICT');

    expect(Article::query()->sole()->summary)->toBe('Correctif de la relance des factures.');
});

it('creates distinct articles for another version or another language', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $token = Factory::apiToken($author);

    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['version' => '2.2.0', 'title' => 'Module XY 2.2']))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['locale' => 'en_US', 'title' => 'Module XY 2.1 en']))->assertCreated();

    expect(Article::query()->count())->toBe(3);
});

it('never matches an announcement without a version', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $token = Factory::apiToken($author);

    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['version' => null, 'title' => 'Annonce A']))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/articles', duplicatePayload($editor, $project, ['version' => null, 'title' => 'Annonce B']))->assertCreated();

    expect(Article::query()->count())->toBe(2);
});

it('sends the web form back to the existing announcement', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($author);
    $pending = Factory::article($author, ['project_id' => $project->getKey(), 'version' => '2.1.0']);
    app(ArticleService::class)->submit($pending, $author);

    $payload = duplicatePayload($editor, $project);
    unset($payload['submit']);

    $this->actingAs($author)
        ->from(route('account.articles.create'))
        ->post(route('account.articles.store'), $payload)
        ->assertRedirect(route('account.articles.create'))
        ->assertSessionHasErrors('version');

    expect(Article::query()->count())->toBe(1);
});
