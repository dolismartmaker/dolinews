<?php

declare(strict_types=1);

use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Models\ProjectMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * Logo and screenshot gallery of a project sheet (SPEC 4.2/4.4): set by
 * the API, shown on the public page, and kept out of the orphan purge.
 */
function galleryPng(int $width): UploadedFile
{
    $image = imagecreatetruecolor($width, 40);

    if ($image === false) {
        throw new RuntimeException('GD image creation failed');
    }

    ob_start();
    imagepng($image);
    $binary = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent('capture-'.$width.'.png', $binary);
}

function galleryMedia(Editor $editor, int $width, ?string $alt = null): Media
{
    return app(MediaService::class)->store(galleryPng($width), $editor, $alt);
}

beforeEach(function (): void {
    Storage::fake(Media::DISK, ['url' => 'https://dolinews.com/storage/media']);
});

it('sets the logo of a sheet and exposes it with the hash of the file as sent', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $upload = galleryPng(60);
    $logo = app(MediaService::class)->store($upload, $editor, 'Logo du module');

    $this->withToken(Factory::apiToken($owner))
        ->putJson('/api/v1/projects/'.$project->slug.'/logo', ['media_id' => $logo->getKey()])
        ->assertOk()
        ->assertJsonPath('data.logo.media_id', $logo->getKey());

    expect($project->refresh()->logo_media_id)->toBe($logo->getKey());

    // The client compares this with the sha256 of its own file: the
    // hash of the re-encoded file is one it cannot compute.
    $this->getJson('/api/v1/projects/'.$project->slug)
        ->assertOk()
        ->assertJsonPath('data.logo.alt', 'Logo du module')
        ->assertJsonPath('data.logo.source_hash', hash_file('sha256', $upload->getRealPath()))
        ->assertJsonPath('data.gallery', []);

    $this->withToken(Factory::apiToken($owner))
        ->putJson('/api/v1/projects/'.$project->slug.'/logo', ['media_id' => null])
        ->assertOk()
        ->assertJsonPath('data.logo', null);
});

it('refuses a medium of another editor', function (): void {
    [$owner] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    [, $otherEditor] = Factory::contributorWithEditor();
    $foreign = galleryMedia($otherEditor, 61);

    $this->withToken(Factory::apiToken($owner))
        ->putJson('/api/v1/projects/'.$project->slug.'/logo', ['media_id' => $foreign->getKey()])
        ->assertForbidden()
        ->assertJsonPath('error', 'FORBIDDEN');

    $this->withToken(Factory::apiToken($owner))
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', ['media_id' => $foreign->getKey()])
        ->assertForbidden();

    expect($project->refresh()->logo_media_id)->toBeNull()
        ->and(ProjectMedia::query()->count())->toBe(0);
});

it('refuses a gallery write to an account outside the editor', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $media = galleryMedia($editor, 62);
    [$stranger] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($stranger))
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', ['media_id' => $media->getKey()])
        ->assertForbidden();

    $this->withToken(Factory::apiToken($stranger))
        ->putJson('/api/v1/projects/'.$project->slug.'/logo', ['media_id' => $media->getKey()])
        ->assertForbidden();
});

it('adds, orders, recaptions and removes gallery images', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $first = galleryMedia($editor, 63, 'Liste des tiers');
    $second = galleryMedia($editor, 64);
    $token = Factory::apiToken($owner);

    $this->withToken($token)
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', [
            'media_id' => $first->getKey(),
            'caption' => 'Page d\'accueil du module',
        ])
        ->assertCreated()
        ->assertJsonPath('data.caption', 'Page d\'accueil du module');

    $this->withToken($token)
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', [
            'media_id' => $second->getKey(),
            'position' => 0,
        ])
        ->assertCreated();

    // Named again: a rerun rewrites the caption, it does not duplicate.
    $this->withToken($token)
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', [
            'media_id' => $first->getKey(),
            'caption' => 'Accueil',
        ])
        ->assertOk()
        ->assertJsonPath('data.caption', 'Accueil');

    $this->getJson('/api/v1/projects/'.$project->slug)
        ->assertOk()
        ->assertJsonCount(2, 'data.gallery')
        ->assertJsonPath('data.gallery.0.media_id', $second->getKey())
        ->assertJsonPath('data.gallery.1.media_id', $first->getKey())
        ->assertJsonPath('data.gallery.1.alt', 'Liste des tiers')
        ->assertJsonPath('data.gallery.1.url', $first->url());

    $this->withToken($token)
        ->deleteJson('/api/v1/projects/'.$project->slug.'/gallery/'.$second->getKey())
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    $this->withToken($token)
        ->deleteJson('/api/v1/projects/'.$project->slug.'/gallery/'.$second->getKey())
        ->assertNotFound();

    // The file stays deposited: the purge decides later.
    expect(Media::query()->find($second->getKey()))->not->toBeNull()
        ->and($project->gallery()->count())->toBe(1);
});

it('bounds the gallery and names the maximum in the refusal', function (): void {
    config()->set('dolinews.projects.gallery_max', 2);

    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $token = Factory::apiToken($owner);

    foreach ([65, 66] as $width) {
        $this->withToken($token)
            ->postJson('/api/v1/projects/'.$project->slug.'/gallery', ['media_id' => galleryMedia($editor, $width)->getKey()])
            ->assertCreated();
    }

    $response = $this->withToken($token)
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', ['media_id' => galleryMedia($editor, 67)->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('error', 'VALIDATION_FAILED')
        ->assertJsonPath('detail.gallery_max', 2);

    expect((string) $response->json('detail.media_id'))->toContain('2 images');
});

it('keeps gallery images through the orphan purge', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $shown = galleryMedia($editor, 68);
    $orphan = galleryMedia($editor, 69);

    $this->withToken(Factory::apiToken($owner))
        ->postJson('/api/v1/projects/'.$project->slug.'/gallery', ['media_id' => $shown->getKey()])
        ->assertCreated();

    $this->travelTo(now()->addDays(2));

    $this->artisan('dolinews:purge-orphan-media')->assertSuccessful();

    expect(Media::query()->find($shown->getKey()))->not->toBeNull()
        ->and(Media::query()->find($orphan->getKey()))->toBeNull();
});

it('shows the logo and the gallery on the public sheet', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $logo = galleryMedia($editor, 70, 'Logo');
    $shot = galleryMedia($editor, 71, 'Liste des factures');

    $project->logo_media_id = $logo->getKey();
    $project->save();

    ProjectMedia::query()->create([
        'project_id' => $project->getKey(),
        'media_id' => $shot->getKey(),
        'position' => 1,
        'caption' => 'Les factures du mois',
    ]);

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))
        ->assertOk()
        ->assertSee($logo->url(), false)
        ->assertSee('Captures d&#039;écran', false)
        // Without script a thumbnail opens its file: the link is the image.
        ->assertSee('href="'.$shot->url().'"', false)
        ->assertSee('alt="Liste des factures"', false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('data-caption="Les factures du mois"', false)
        ->assertSee('data-gallery-viewer', false)
        ->assertSee('aria-label="Capture suivante"', false);
});

it('shows no gallery section on a sheet without images', function (): void {
    [$owner] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))
        ->assertOk()
        ->assertDontSee('data-gallery', false);
});
