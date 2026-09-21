<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Media\MediaException;
use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * Media intake (SPEC 7, D7): re-encode everything, refuse SVG, strip
 * EXIF through the rewrite, dedup on the re-encoded hash, purge
 * orphans.
 */
function gdPngUpload(int $width = 90, int $height = 60): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('GD image creation failed');
    }

    $blue = imagecolorallocate($image, 30, 64, 175);

    if ($blue !== false) {
        imagefilledrectangle($image, 0, 0, $width, $height, $blue);
    }

    ob_start();
    imagepng($image);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent('capture.png', $binary);
}

function makeEditor(): Editor
{
    return (new EditorService)->create(User::factory()->create(), [
        'name' => 'Editeur media',
        'contact_email' => 'media@editeur.test',
    ]);
}

it('stores a re-encoded clean png', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $media = app(MediaService::class)->store(gdPngUpload(), $editor, 'Capture d\'ecran du module');

    expect($media->mime)->toBe('image/png')
        ->and($media->width)->toBe(90)
        ->and($media->hash)->toMatch('/^[0-9a-f]{64}$/');

    Storage::disk(Media::DISK)->assertExists($media->path);
});

it('refuses svg files whatever their disguise', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $service = app(MediaService::class);

    $refusals = 0;

    // Straight extension.
    try {
        $service->store(
            UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            $editor,
        );
    } catch (MediaException) {
        $refusals++;
    }

    // Renamed with a png extension: the content sniff must still refuse.
    try {
        $service->store(
            UploadedFile::fake()->createWithContent('evil.png', '<?xml version="1.0"?><svg onload="alert(1)"></svg>'),
            $editor,
        );
    } catch (MediaException) {
        $refusals++;
    }

    expect($refusals)->toBe(2);
});

it('refuses undecodable payloads', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();

    app(MediaService::class)->store(
        UploadedFile::fake()->createWithContent('fake.png', 'not a bitmap at all'),
        $editor,
    );
})->throws(MediaException::class);

it('deduplicates identical uploads within one editor', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $upload = gdPngUpload();

    $one = app(MediaService::class)->store($upload, $editor);
    $two = app(MediaService::class)->store($upload, $editor);

    expect($two->getKey())->toBe($one->getKey());
});

it('rescales oversized images to the configured longest side', function (): void {
    Storage::fake(Media::DISK);
    config()->set('dolinews.media.max_dimension', 100);

    $editor = makeEditor();
    $media = app(MediaService::class)->store(gdPngUpload(width: 600, height: 300), $editor);

    expect($media->width)->toBe(100)
        ->and($media->height)->toBe(50);
});

it('purges orphan media past the grace period only', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $orphan = app(MediaService::class)->store(gdPngUpload(), $editor);

    $bound = app(MediaService::class)->store(gdPngUpload(width: 33), $editor);
    $bound->article_id = Factory::article(User::factory()->create())->getKey();
    $bound->save();

    $this->travelTo(now()->addDays(2));

    $purged = app(MediaService::class)->purgeOrphans();

    expect($purged)->toBe(1)
        ->and(Media::query()->find($orphan->getKey()))->toBeNull()
        // The bound media survives whatever its age.
        ->and(Media::query()->find($bound->getKey()))->not->toBeNull();

    Storage::disk(Media::DISK)->assertMissing($orphan->path);
});
