<?php

declare(strict_types=1);

use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * The feed page and the search of the header (SPEC 6.1).
 *
 * The free-text search sits in the header of every public page, the
 * structured filters in the side column of the feed. Each form carries
 * what the other one set, so that narrowing never throws a criterion away.
 */
it('offers the search of the feed from a page that is not the feed', function (): void {
    $this->get('/fr/regles')
        ->assertOk()
        ->assertSee('role="search"', false)
        ->assertSee('id="header-q"', false)
        ->assertSee('action="'.route('home').'"', false);
});

it('keeps the filters already set when a new search is typed on the feed', function (): void {
    $this->get('/fr?editor=cap-rel&focus=security&maturity[]=beta')
        ->assertOk()
        ->assertSee('<input type="hidden" name="editor" value="cap-rel">', false)
        ->assertSee('<input type="hidden" name="focus" value="security">', false)
        ->assertSee('<input type="hidden" name="maturity[]" value="beta">', false);
});

it('keeps the search when the filters of the side column are applied', function (): void {
    $this->get('/fr?q=caisse')
        ->assertOk()
        ->assertSee('value="caisse"', false)
        ->assertSee('<input type="hidden" name="q" value="caisse">', false);
});

it('unfolds the filters on a phone only when one is already set', function (): void {
    $this->get('/fr')->assertOk()->assertDontSee('id="refine-toggle" class="peer sr-only" checked', false);

    $this->get('/fr?project=dolinews')->assertOk()->assertSee('id="refine-toggle" class="peer sr-only" checked', false);
});

it('shows the logo of the sheet on the card, and the one of the editor without it', function (): void {
    Storage::fake(Media::DISK);

    [$owner, $editor] = Factory::contributorWithEditor();
    $service = app(MediaService::class);

    $editorLogo = $service->store(UploadedFile::fake()->image('editor.png', 64, 64), $editor);
    $editor->logo_media_id = $editorLogo->getKey();
    $editor->save();

    $sheetLogo = $service->store(UploadedFile::fake()->image('sheet.png', 80, 48), $editor);
    $project = Factory::projectFor($owner);
    $project->logo_media_id = $sheetLogo->getKey();
    $project->save();

    Factory::publishedArticle($owner, ['title' => 'Avec fiche', 'project_id' => $project->getKey()]);

    $this->get('/fr')
        ->assertOk()
        ->assertSee('src="'.$sheetLogo->url().'"', false)
        ->assertDontSee('src="'.$editorLogo->url().'"', false);

    $project->logo_media_id = null;
    $project->save();

    $this->get('/fr')
        ->assertOk()
        ->assertSee('src="'.$editorLogo->url().'"', false);
});

it('leaves no empty slot on a card whose source has no logo', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Sans logo']);

    $this->get('/fr')
        ->assertOk()
        ->assertSee('Sans logo')
        ->assertDontSee('h-10 w-10 shrink-0 rounded-lg', false);
});
