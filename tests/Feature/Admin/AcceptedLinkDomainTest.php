<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\AcceptedLinkDomain;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectLink;
use App\Domain\Dolinews\Projects\LinkPolicy;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Livewire\Admin\ProjectList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Domains accepted for every sheet (SPEC 8): the super admin grows the
 * list from the flagged links of a sheet, and the domain alert leaves
 * them aside.
 */

/**
 * A sheet whose editor lives on acme.example, with one demo link elsewhere.
 *
 * @return array{0: Project, 1: ProjectLink}
 */
function sheetWithDemoLink(): array
{
    $project = Factory::projectFor(User::factory()->create(), ['name' => 'Fiche démo']);
    $project->editor->forceFill(['website' => 'https://acme.example'])->save();
    $link = app(ProjectService::class)->addLink($project, LinkType::DEMO, 'https://demo.dolitest.fr/module');

    return [$project, $link];
}

/**
 * @return array<string, string>
 */
function domainAlerts(User $viewer): array
{
    $rows = Livewire::actingAs($viewer)->test(ProjectList::class)->viewData('rows');

    return collect($rows->items())->mapWithKeys(fn (array $row): array => [$row['name'] => $row['domain_alert']])->all();
}

it('offers the host and its parent domain as levels', function (): void {
    expect(LinkPolicy::domainLevels('demo.dolitest.fr'))->toBe(['demo.dolitest.fr', 'dolitest.fr'])
        ->and(LinkPolicy::domainLevels('dolistore.com'))->toBe(['dolistore.com']);
});

it('accepts a parent domain from a flagged link, and the alert leaves its subdomains aside', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [$project, $link] = sheetWithDemoLink();

    expect(domainAlerts($admin)['Fiche démo'])->toBe('1');

    Livewire::actingAs($admin)
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->assertSee('Accepter dolitest.fr')
        ->call('acceptDomain', $link->id, 1)
        ->assertHasNoErrors();

    expect(AcceptedLinkDomain::query()->pluck('domain')->all())->toBe(['dolitest.fr'])
        ->and(domainAlerts($admin)['Fiche démo'])->toBe('');

    // The same rule in the panel as in the column: the link is no longer
    // flagged, so no acceptance is offered on it.
    $panel = Livewire::actingAs($admin)
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey());

    expect($panel->instance()->isOffDomain($link->id))->toBeFalse();
    $panel->assertDontSee('Accepter demo.dolitest.fr');
});

it('counts the links again once their domain leaves the list', function (): void {
    $admin = User::factory()->superAdmin()->create();
    sheetWithDemoLink();
    $accepted = AcceptedLinkDomain::query()->create(['domain' => 'dolitest.fr']);

    expect(domainAlerts($admin)['Fiche démo'])->toBe('');

    Livewire::actingAs($admin)
        ->test(ProjectList::class)
        ->call('removeDomain', $accepted->id)
        ->assertHasNoErrors();

    expect(AcceptedLinkDomain::query()->count())->toBe(0)
        ->and(domainAlerts($admin)['Fiche démo'])->toBe('1');
});

it('accepts no domain a flagged link of the open sheet does not carry', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [$project, $link] = sheetWithDemoLink();

    Livewire::actingAs($admin)
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->call('acceptDomain', $link->id, 5);

    expect(AcceptedLinkDomain::query()->count())->toBe(0);
});

it('keeps the list to the super admin', function (): void {
    [$project, $link] = sheetWithDemoLink();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->assertDontSee('Accepter dolitest.fr')
        ->call('acceptDomain', $link->id, 1)
        ->assertForbidden();

    expect(AcceptedLinkDomain::query()->count())->toBe(0);
});
