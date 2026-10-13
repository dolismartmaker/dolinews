<?php

declare(strict_types=1);

use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The journal read by the party it targets (SPEC 9.4).
 */
it('shows the author the acts on its articles and on its editor sheets, with rule and motive', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author, ['title' => 'Annonce masquée']);
    $project = Factory::projectFor($author, ['name' => 'Fiche masquée']);
    $moderator = User::factory()->moderator()->create(['display_name' => 'Camille Relectrice']);

    $moderation = app(ModerationService::class);
    $moderation->hideArticle($article, $moderator, 'Lien trompeur vers une destination sans rapport.', 'R4');
    $moderation->hideProject($project, $moderator, 'Fiche usurpant un autre projet.', 'R2', conflictOfInterest: true);

    $this->actingAs($author)
        ->get(route('account.moderation'))
        ->assertOk()
        ->assertSee('Annonce masquée')
        ->assertSee('Lien trompeur vers une destination sans rapport.')
        ->assertSee('Règle R4')
        ->assertSee('Fiche masquée')
        ->assertSee('sept jours')
        // The act is the team's: the moderator is never named to its target.
        ->assertDontSee('Camille Relectrice')
        ->assertDontSee($moderator->email);
});

it('shows a reader the acts on its own account', function (): void {
    $reader = User::factory()->create();
    app(ModerationService::class)->warn($reader, User::factory()->moderator()->create(), 'Abonnements en série sur des adresses de tiers.', 'R1');

    $this->actingAs($reader)
        ->get(route('account.moderation'))
        ->assertOk()
        ->assertSee('Votre compte')
        ->assertSee('Abonnements en série sur des adresses de tiers.');
});

it('never shows the acts that concern someone else', function (): void {
    $other = User::factory()->create();
    $article = Factory::publishedArticle($other, ['title' => 'Annonce d\'un tiers']);
    app(ModerationService::class)->hideArticle($article, User::factory()->moderator()->create(), 'Contenu sans rapport avec Dolibarr.', 'R1');

    $this->actingAs(User::factory()->create())
        ->get(route('account.moderation'))
        ->assertOk()
        ->assertDontSee('Annonce d&#039;un tiers', false)
        ->assertSee('Aucun acte de modération ne vous concerne.');
});
