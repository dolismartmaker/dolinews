<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Folding a burst of announcements on the feed page (SPEC 6.1).
 *
 * An editor shipping four fixes in an afternoon is doing what the
 * service asks, and the limits now let them (SPEC 5.3). The page must
 * not punish the reader for it by handing one project the whole screen.
 *
 * It folds, it never hides: the count is stated, each announcement stays
 * one click away, security announcements never fold, and the feeds, the
 * API and the emails keep serving every entry.
 */
function burst(User $author, int $count, string $prefix = 'Module Rafale', array $overrides = []): array
{
    $project = Factory::projectFor($author);
    $articles = [];

    for ($i = 0; $i < $count; $i++) {
        $article = Factory::publishedArticle($author, array_merge([
            'title' => $prefix.' '.$i.'.0',
        ], $overrides));

        $article->forceFill([
            'project_id' => $project->getKey(),
            'published_at' => now()->subHours($count - $i),
        ])->save();

        $articles[] = $article;
    }

    return $articles;
}

it('folds the older announcements of one project under the newest', function (): void {
    $author = Factory::contributorWithoutEditor();
    burst($author, 4);

    $response = $this->get('/fr');

    $response->assertOk()
        // The newest stays in full.
        ->assertSee('Module Rafale 3.0')
        // The others are still served, inside the folded block.
        ->assertSee('Module Rafale 0.0')
        ->assertSee('3 versions plus anciennes de ce projet');
});

it('leaves a short series alone', function (): void {
    $author = Factory::contributorWithoutEditor();
    burst($author, 2);

    // Below the threshold nothing folds: two announcements of a project
    // are a feed, not a burst.
    $this->get('/fr')->assertOk()->assertDontSee('versions plus anciennes de ce projet');
});

it('never folds a security announcement', function (): void {
    $author = Factory::contributorWithoutEditor();
    burst($author, 4, 'Module Rafale', ['focus' => 'security']);

    // Folding it would hide exactly what the reader came for, and what
    // the exemption of SPEC 5.3 exists to let through.
    $this->get('/fr')->assertOk()
        ->assertDontSee('versions plus anciennes de ce projet')
        ->assertSee('Module Rafale 0.0');
});

it('keeps two projects apart', function (): void {
    $author = Factory::contributorWithoutEditor();
    $other = Factory::contributorWithoutEditor();

    burst($author, 3);
    burst($other, 3, 'Autre module');

    // Folding groups announcements already adjacent in the feed, and
    // only those of the same sheet.
    $this->get('/fr')->assertOk()
        ->assertSee('Module Rafale 2.0')
        ->assertSee('Autre module 2.0');
});

it('serves every entry in the RSS feed', function (): void {
    $author = Factory::contributorWithoutEditor();
    burst($author, 4);

    // An entry already distributed that disappears from a feed is a
    // broken contract: folding is a matter of page.
    $content = (string) $this->get('/feeds.xml')->assertOk()->getContent();

    foreach (range(0, 3) as $i) {
        expect($content)->toContain('Module Rafale '.$i.'.0');
    }
});

it('shows every version of a project on a search', function (): void {
    $author = Factory::contributorWithoutEditor();
    burst($author, 4);

    // Someone looking for a module wants its versions, not the last one.
    $this->get('/fr?q=Rafale')->assertOk()
        ->assertSee('Module Rafale 3.0')
        ->assertDontSee('versions plus anciennes de ce projet');
});

it('shows every version on the project sheet', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = burst($author, 4);

    $slug = Article::query()->findOrFail($articles[0]->getKey())->project?->slug;

    // The sheet is the place where one project is the whole subject:
    // folding it there would answer the opposite of the question.
    $this->get('/fr/projets/'.$slug)->assertOk()
        ->assertSee('Module Rafale 3.0')
        ->assertSee('Module Rafale 0.0')
        ->assertDontSee('versions plus anciennes de ce projet');
});
