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

/**
 * Encode a GD canvas into an upload of the given format.
 */
function gdUpload(string $name, callable $encoder, int $width = 90, int $height = 60, bool $alpha = false): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('GD image creation failed');
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    $fill = $alpha
        ? imagecolorallocatealpha($image, 30, 64, 175, 100)
        : imagecolorallocate($image, 30, 64, 175);

    if ($fill !== false) {
        imagefilledrectangle($image, 0, 0, $width, $height, $fill);
    }

    ob_start();
    $encoder($image);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent($name, $binary);
}

/**
 * The first image chunk of a stored WebP: "VP8 " lossy, "VP8L" lossless.
 */
function storedWebpChunk(Media $media): string
{
    $bytes = (string) Storage::disk(Media::DISK)->get($media->path);
    $offset = 12;

    while ($offset + 8 <= strlen($bytes)) {
        $fourcc = substr($bytes, $offset, 4);

        if ($fourcc === 'VP8 ' || $fourcc === 'VP8L') {
            return $fourcc;
        }

        $size = (int) (unpack('V', substr($bytes, $offset + 4, 4)) ?: [1 => 0])[1];
        $offset += 8 + $size + ($size % 2);
    }

    return '';
}

it('stores a re-encoded clean webp', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $media = app(MediaService::class)->store(gdPngUpload(), $editor, 'Capture d\'ecran du module');

    expect($media->mime)->toBe('image/webp')
        ->and($media->path)->toEndWith('.webp')
        ->and($media->width)->toBe(90)
        ->and($media->hash)->toMatch('/^[0-9a-f]{64}$/');

    Storage::disk(Media::DISK)->assertExists($media->path);
    expect(substr((string) Storage::disk(Media::DISK)->get($media->path), 8, 4))->toBe('WEBP');
});

it('rewrites a screenshot losslessly and keeps its transparency', function (): void {
    Storage::fake(Media::DISK);

    $media = app(MediaService::class)->store(
        gdUpload('logo.png', fn (GdImage $image) => imagepng($image), alpha: true),
        makeEditor(),
    );

    expect(storedWebpChunk($media))->toBe('VP8L');

    $stored = imagecreatefromstring((string) Storage::disk(Media::DISK)->get($media->path));
    expect($stored)->toBeInstanceOf(GdImage::class);

    /** @var GdImage $stored */
    $pixel = imagecolorsforindex($stored, imagecolorat($stored, 10, 10));
    expect($pixel['alpha'])->toBe(100);
});

it('rewrites a photo lossily', function (): void {
    Storage::fake(Media::DISK);

    $media = app(MediaService::class)->store(
        gdUpload('photo.jpg', fn (GdImage $image) => imagejpeg($image, null, 90)),
        makeEditor(),
    );

    expect($media->mime)->toBe('image/webp')
        ->and(storedWebpChunk($media))->toBe('VP8 ');
});

it('keeps the mode a webp was sent in', function (): void {
    Storage::fake(Media::DISK);

    $editor = makeEditor();
    $service = app(MediaService::class);

    $lossy = $service->store(gdUpload('photo.webp', fn (GdImage $image) => imagewebp($image, null, 80)), $editor);
    $lossless = $service->store(
        gdUpload('capture.webp', fn (GdImage $image) => imagewebp($image, null, IMG_WEBP_LOSSLESS), width: 91),
        $editor,
    );

    expect(storedWebpChunk($lossy))->toBe('VP8 ')
        ->and(storedWebpChunk($lossless))->toBe('VP8L');
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

it('keeps the media a sheet or an editor holds through the purge command', function (): void {
    Storage::fake(Media::DISK);

    [$owner, $editor] = Factory::contributorWithEditor();
    $service = app(MediaService::class);

    $sheetLogo = $service->store(gdPngUpload(width: 41), $editor);
    $editorLogo = $service->store(gdPngUpload(width: 42), $editor);
    $quoted = $service->store(gdPngUpload(width: 43), $editor);
    $orphan = $service->store(gdPngUpload(width: 44), $editor);

    $project = Factory::projectFor($owner, [
        'description' => "## Captures\n\n![Accueil](".$quoted->url().')',
    ]);
    $project->logo_media_id = $sheetLogo->getKey();
    $project->save();

    $editor->logo_media_id = $editorLogo->getKey();
    $editor->save();

    $this->travelTo(now()->addDays(2));

    $this->artisan('dolinews:purge-orphan-media')->assertSuccessful();

    // A logo is bound to no article: purging it took the picture off the
    // sheet the day after it was set.
    expect(Media::query()->find($sheetLogo->getKey()))->not->toBeNull()
        ->and(Media::query()->find($editorLogo->getKey()))->not->toBeNull()
        ->and(Media::query()->find($quoted->getKey()))->not->toBeNull()
        // What nothing references still goes.
        ->and(Media::query()->find($orphan->getKey()))->toBeNull();

    Storage::disk(Media::DISK)->assertExists($sheetLogo->path);
    Storage::disk(Media::DISK)->assertMissing($orphan->path);
});

/**
 * A palette PNG, which is what every optimised PNG is: 200x120. The top
 * half is split between two known entries (the left one optionally
 * declared transparent), the bottom half is a pattern over 200 entries,
 * so that a real encoding weighs more than an empty picture.
 */
function palettePngUpload(bool $transparentIndex = false): UploadedFile
{
    $image = imagecreate(200, 120);

    if ($image === false) {
        throw new RuntimeException('GD image creation failed');
    }

    imagecolorallocate($image, 30, 64, 175);
    $second = imagecolorallocate($image, 220, 38, 38);

    if ($second === false) {
        throw new RuntimeException('GD colour allocation failed');
    }

    imagefilledrectangle($image, 0, 0, 99, 59, $second);

    $palette = [];

    for ($i = 0; $i < 200; $i++) {
        $palette[] = (int) imagecolorallocate($image, $i, (int) ($i * 7 % 256), (int) ($i * 13 % 256));
    }

    for ($y = 60; $y < 120; $y++) {
        for ($x = 0; $x < 200; $x++) {
            imagesetpixel($image, $x, $y, $palette[($x * 31 + $y * 17 + $x * $y) % 200]);
        }
    }

    if ($transparentIndex) {
        imagecolortransparent($image, $second);
    }

    ob_start();
    imagepng($image);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent('logo.png', $binary);
}

/**
 * Upload through the API and decode what was stored.
 *
 * @return array{0: Media, 1: GdImage}
 */
function depositPalettePng(bool $transparentIndex): array
{
    Storage::fake(Media::DISK);

    [$user, $editor] = Factory::contributorWithEditor();

    $response = test()->withToken(Factory::apiToken($user))
        ->post('/api/v1/media', [
            'editor_id' => $editor->getKey(),
            'file' => palettePngUpload($transparentIndex),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.mime', 'image/webp')
        ->assertJsonPath('data.width', 200)
        ->assertJsonPath('data.height', 120);

    /** @var Media $media */
    $media = Media::query()->findOrFail($response->json('data.id'));
    $binary = (string) Storage::disk(Media::DISK)->get($media->path);

    expect(strlen($binary))->toBe($media->bytes)
        ->and($media->bytes)->toBeGreaterThan(2000)
        ->and(getimagesizefromstring($binary))->toMatchArray([0 => 200, 1 => 120, 'mime' => 'image/webp']);

    $stored = imagecreatefromstring($binary);
    expect($stored)->toBeInstanceOf(GdImage::class);

    /** @var GdImage $stored */
    expect(imagesx($stored))->toBe(200)
        ->and(imagesy($stored))->toBe(120);

    return [$media, $stored];
}

it('stores a palette png uploaded through the api as a real webp', function (): void {
    [$media, $stored] = depositPalettePng(transparentIndex: false);

    expect(storedWebpChunk($media))->toBe('VP8L')
        ->and(imagecolorsforindex($stored, imagecolorat($stored, 20, 20)))
        ->toMatchArray(['red' => 220, 'green' => 38, 'blue' => 38, 'alpha' => 0])
        ->and(imagecolorsforindex($stored, imagecolorat($stored, 150, 20)))
        ->toMatchArray(['red' => 30, 'green' => 64, 'blue' => 175, 'alpha' => 0]);
});

it('keeps the transparent index of a palette png as alpha', function (): void {
    [, $stored] = depositPalettePng(transparentIndex: true);

    expect(imagecolorsforindex($stored, imagecolorat($stored, 20, 20))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($stored, imagecolorat($stored, 150, 20)))
        ->toMatchArray(['red' => 30, 'green' => 64, 'blue' => 175, 'alpha' => 0]);
});
