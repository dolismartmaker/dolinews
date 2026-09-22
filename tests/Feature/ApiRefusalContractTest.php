<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Tests\Support\Factory;

/**
 * What a refusal says on the wire (SPEC 5.2, docs/API.md).
 *
 * A client that submits a catalogue reads these codes to decide what to
 * do: wait out a minute, wait for the review team, or stop. Both cases
 * below answered something else until they were pinned here - the rate
 * limiter replied with the framework's bare "Too Many Attempts.", and a
 * full queue was reported as an empty bucket, which sends an operator
 * raising the wrong setting.
 */
it('names the rate limiter in the error envelope', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $token = Factory::apiToken($author);

    $payload = [
        'editor_id' => $editor->getKey(),
        'type' => 'announcement',
        'title' => 'Titre de contrôle',
        'summary' => 'Résumé de contrôle.',
        'body' => 'Corps de contrôle.',
        'locale' => 'fr_FR',
    ];

    // The write throttle allows ten calls per minute and per account.
    $response = null;

    for ($attempt = 0; $attempt < 11; $attempt++) {
        $response = $this->withToken($token)->postJson('/api/v1/articles', $payload);
    }

    $response->assertStatus(429)
        ->assertJsonPath('error', 'RATE_LIMITED')
        ->assertJsonStructure(['error', 'message']);
});

it('tells a full review queue apart from an empty bucket', function (): void {
    config()->set('dolinews.quota.queue_ceiling', 2);

    [$author, $editor] = Factory::contributorWithEditor();
    $articles = app(ArticleService::class);

    // Fill the editor's queue: each article sits pending.
    foreach (range(1, 2) as $index) {
        $pending = Factory::article($author, ['title' => 'En revue '.$index]);
        $articles->submit($pending, $author);
    }

    $response = $this->withToken(Factory::apiToken($author))
        ->postJson('/api/v1/articles', [
            'editor_id' => $editor->getKey(),
            'type' => 'announcement',
            'title' => 'Une annonce de trop',
            'summary' => 'Elle arrive alors que la file de l\'éditeur est pleine.',
            'body' => 'Corps de l\'annonce.',
            'locale' => 'fr_FR',
            'submit' => true,
        ]);

    $response->assertStatus(429)
        ->assertJsonPath('error', 'QUEUE_CEILING_REACHED');
});

it('leaves no draft behind when the quota refuses the submission', function (): void {
    config()->set('dolinews.quota.queue_ceiling', 1);

    [$author, $editor] = Factory::contributorWithEditor();
    $pending = Factory::article($author, ['title' => 'Déjà en revue']);
    app(ArticleService::class)->submit($pending, $author);

    $before = Article::query()->count();

    $this->withToken(Factory::apiToken($author))
        ->postJson('/api/v1/articles', [
            'editor_id' => $editor->getKey(),
            'type' => 'announcement',
            'title' => 'Refusée par le quota',
            'summary' => 'Elle ne doit pas rester en brouillon derrière le refus.',
            'body' => 'Corps de l\'annonce.',
            'locale' => 'fr_FR',
            'submit' => true,
        ])->assertStatus(429);

    // Creation and submission are one act: a refusal undoes both, or
    // the next attempt duplicates an article nobody saw.
    expect(Article::query()->count())->toBe($before);
});

it('reports an empty bucket as such', function (): void {
    config()->set('dolinews.quota.bucket_capacity', 1);
    config()->set('dolinews.quota.queue_ceiling', 50);

    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    // A second article on the same project: the bucket holds one token,
    // the first submission reserved it.
    $second = Factory::article($author, ['title' => 'Deuxième version', 'project_id' => $article->project_id]);

    $response = $this->withToken(Factory::apiToken($author))
        ->postJson('/api/v1/articles/'.$second->getKey().'/submit');

    $response->assertStatus(429)
        ->assertJsonPath('error', 'QUOTA_BUCKET_EMPTY');
});
