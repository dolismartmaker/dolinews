<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Media;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectMedia;
use App\Domain\Dolinews\Models\ProjectTranslation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Media intake (SPEC 7, D7): an upload is an attack vector, declared
 * MIME type and extension are never proofs.
 *
 * Every image is fully re-encoded to WebP: the rewrite produces a clean file,
 * neutralizes polyglot files, and drops EXIF metadata as a side effect.
 * SVG is refused: the format embeds script. Identical files (same sha256
 * after re-encoding) deduplicate per editor.
 */
class MediaService
{
    /** Every media is stored in this format, whatever the source. */
    private const OUTPUT_MIME = 'image/webp';

    private const LOSSY_QUALITY = 85;

    public function __construct()
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension is required for media re-encoding.');
        }

        if (! function_exists('imagewebp')) {
            throw new \RuntimeException('GD must be built with WebP support: media are stored as WebP.');
        }
    }

    /**
     * Store an uploaded image: validate, decode, rescale, re-encode,
     * deduplicate, persist.
     *
     * @throws MediaException when the file is refused (too large, SVG,
     *                        undecodable bitmap).
     */
    public function store(UploadedFile $file, Editor $editor, ?string $alt = null): Media
    {
        $maxBytes = (int) config('dolinews.media.max_bytes');

        if ($file->getSize() > $maxBytes) {
            throw new MediaException('Fichier trop volumineux (maximum '.round($maxBytes / 1048576).' Mo).');
        }

        $this->assertNotSvg($file);

        // getimagesize reads the actual header: a renamed or polyglot
        // file that GD cannot decode dies here, before any storage.
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            Log::warning('MediaService: file is not a decodable bitmap', [
                'editor' => $editor->getKey(),
                'client_mime' => (string) $file->getClientMimeType(),
            ]);

            throw new MediaException('Le fichier n\'est pas une image bitmap lisible.');
        }

        [$width, $height, $type] = $info;

        // Taken on the bytes as received: media.hash is taken after the
        // re-encoding, which a client cannot reproduce, so this is what
        // lets it tell a file already deposited from a new one.
        $sourceHash = (string) hash_file('sha256', $file->getRealPath());

        $image = $this->decode($file->getRealPath(), $type);

        if ($image === null) {
            throw new MediaException('Le fichier n\'est pas une image bitmap lisible.');
        }

        try {
            $final = $this->rescale($image, $width, $height) ?? $image;

            try {
                $binary = $this->encode($final, $this->isLosslessSource($file->getRealPath(), $type));
                $outWidth = imagesx($final);
                $outHeight = imagesy($final);
            } finally {
                // Only the rescaled copy belongs to this block; $image is
                // released by the outer finally.
                if ($final !== $image) {
                    imagedestroy($final);
                }
            }

            $hash = hash('sha256', $binary);

            // Deduplication per editor (SPEC 7): same bytes after
            // re-encoding, same media row.
            /** @var Media|null $existing */
            $existing = Media::query()
                ->where('editor_id', $editor->getKey())
                ->where('hash', $hash)
                ->first();

            if ($existing !== null) {
                if ($alt !== null && $existing->alt !== $alt) {
                    $existing->alt = $alt;
                }

                $existing->source_hash = $sourceHash;
                $existing->save();

                return $existing;
            }

            $path = $editor->getKey().'/'.substr($hash, 0, 2).'/'.$hash.'.webp';

            Storage::disk(Media::DISK)->put($path, $binary);

            return Media::query()->create([
                'editor_id' => $editor->getKey(),
                'article_id' => null,
                'path' => $path,
                'mime' => self::OUTPUT_MIME,
                'width' => $outWidth,
                'height' => $outHeight,
                'bytes' => strlen($binary),
                'hash' => $hash,
                'source_hash' => $sourceHash,
                'alt' => $alt,
            ]);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Bind media to the article that references them (SPEC 5.2, step 2
     * of the two-step illustrated publication).
     *
     * Media of another editor, or already bound, stay out of the scope:
     * the returned count lets the caller report the gap.
     *
     * @param  list<int>  $mediaIds
     * @return int number of media actually bound
     */
    public function bindToArticle(array $mediaIds, Article $article): int
    {
        if ($mediaIds === []) {
            return 0;
        }

        return Media::query()
            ->whereIn('id', $mediaIds)
            ->where('editor_id', $article->editor_id)
            ->whereNull('article_id')
            ->update(['article_id' => $article->getKey()]);
    }

    /**
     * Purge orphan media beyond the grace period (SPEC 5.2): uploaded
     * and referenced by nothing.
     *
     * article_id is only one of the ways a file is held. A sheet logo,
     * an editor logo, a gallery image, an image quoted in a sheet
     * description and the image an article puts forward are not bound to
     * any article, and purging them took the picture off a
     * sheet the day after it was set, without an error anywhere.
     *
     * @return int number of purged rows
     */
    public function purgeOrphans(): int
    {
        $hours = max(1, (int) config('dolinews.media.orphan_hours', 24));
        $deadline = now()->subHours($hours);

        $orphans = Media::query()
            ->whereNull('article_id')
            ->where('created_at', '<', $deadline)
            ->whereNotIn('id', Project::query()->whereNotNull('logo_media_id')->select('logo_media_id'))
            ->whereNotIn('id', Editor::query()->whereNotNull('logo_media_id')->select('logo_media_id'))
            ->whereNotIn('id', ProjectMedia::query()->select('media_id'))
            ->whereNotIn('id', Article::query()->whereNotNull('featured_media_id')->select('featured_media_id'))
            ->get()
            ->reject(fn (Media $media): bool => $this->isQuotedBySheet($media))
            ->values();

        foreach ($orphans as $media) {
            DB::transaction(function () use ($media): void {
                Storage::disk(Media::DISK)->delete($media->path);
                $media->delete();
            });
        }

        if ($orphans->isNotEmpty()) {
            Log::info('MediaService: orphan media purged', ['count' => $orphans->count()]);
        }

        return $orphans->count();
    }

    /**
     * Whether a sheet description, in any of its languages, embeds this
     * file. The path carries the sha256 of the file, so a substring
     * match cannot mistake one medium for another.
     */
    private function isQuotedBySheet(Media $media): bool
    {
        $needle = '%'.$media->path.'%';

        return Project::query()->where('description', 'like', $needle)->exists()
            || ProjectTranslation::query()->where('description', 'like', $needle)->exists();
    }

    /**
     * SVG carries script: refused outright, sanitized or not (SPEC 7).
     */
    private function assertNotSvg(UploadedFile $file): void
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'svg') {
            throw new MediaException('Le format SVG est refusé : il embarque du script.');
        }

        if ($file->getClientMimeType() === 'image/svg+xml') {
            throw new MediaException('Le format SVG est refusé : il embarque du script.');
        }

        $head = (string) @file_get_contents($file->getRealPath(), false, null, 0, 2048);

        if (stripos($head, '<svg') !== false || stripos($head, '<?xml') !== false && stripos($head, 'svg') !== false) {
            throw new MediaException('Le format SVG est refusé : il embarque du script.');
        }
    }

    /**
     * Decode a bitmap of the given GD constant type into a truecolor image.
     *
     * @return \GdImage|null null when this GD build cannot read the type
     */
    private function decode(string $path, int $type): ?\GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($path)
                : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp')
                ? @imagecreatefrombmp($path)
                : false,
            default => false,
        };

        if (! $image instanceof \GdImage) {
            return null;
        }

        // Palette images (GIF, every optimised PNG) cannot be written as
        // WebP: depending on the libgd build, imagewebp() either warns or
        // returns an empty picture. Converting here hands a truecolor
        // image to every later step; a transparent palette index becomes
        // a fully transparent pixel.
        if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
            Log::warning('MediaService: palette to truecolor conversion failed', ['type' => $type]);
            imagedestroy($image);

            return null;
        }

        return $image;
    }

    /**
     * Rescale when the longest side exceeds the configured maximum.
     *
     * @return \GdImage|null a new image, or null when no rescale was needed
     */
    private function rescale(\GdImage $image, int $width, int $height): ?\GdImage
    {
        $max = (int) config('dolinews.media.max_dimension', 1600);
        $longest = max($width, $height);

        if ($longest <= $max) {
            return null;
        }

        $ratio = $max / $longest;
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($newWidth, $newHeight);

        if ($target === false) {
            return null;
        }

        // Preserve alpha: the WebP output keeps it.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    /**
     * Re-encode the image: the full rewrite is the point (D7), it drops
     * every metadata block and neutralizes polyglots.
     *
     * Everything is stored as WebP, which weighs less than PNG or JPEG
     * for the same rendering. Photographic sources go lossy (quality
     * 85), everything else lossless: screenshots stay crisp, alpha is
     * kept by both modes.
     *
     * @return string binary content
     */
    private function encode(\GdImage $image, bool $lossless): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $written = imagewebp($image, null, $lossless ? IMG_WEBP_LOSSLESS : self::LOSSY_QUALITY);
        $binary = (string) ob_get_clean();

        if (! $written || $binary === '') {
            Log::error('MediaService: WebP encoding failed', ['lossless' => $lossless]);

            throw new MediaException('Le fichier n\'a pas pu être réencodé.');
        }

        $this->assertDecodable($binary, imagesx($image), imagesy($image), $lossless);

        return $binary;
    }

    /**
     * Decode the produced WebP back before it is stored. Some libgd builds
     * report success on an input they cannot encode and write a few dozen
     * bytes of empty picture: a non-empty string proves nothing, only a
     * decode at the expected size does.
     *
     * @throws MediaException when the output is not the picture it should be
     */
    private function assertDecodable(string $binary, int $width, int $height, bool $lossless): void
    {
        $decoded = @imagecreatefromstring($binary);

        if (! $decoded instanceof \GdImage) {
            Log::error('MediaService: WebP output does not decode', [
                'lossless' => $lossless,
                'bytes' => strlen($binary),
            ]);

            throw new MediaException('Le fichier n\'a pas pu être réencodé.');
        }

        $decodedWidth = imagesx($decoded);
        $decodedHeight = imagesy($decoded);
        imagedestroy($decoded);

        if ($decodedWidth !== $width || $decodedHeight !== $height) {
            Log::error('MediaService: WebP output has unexpected dimensions', [
                'lossless' => $lossless,
                'expected' => $width.'x'.$height,
                'decoded' => $decodedWidth.'x'.$decodedHeight,
            ]);

            throw new MediaException('Le fichier n\'a pas pu être réencodé.');
        }
    }

    /**
     * Whether the source deserves a lossless rewrite. A JPEG is a photo,
     * already lossy: lossless would only inflate it. A WebP keeps the
     * mode it was sent in, read from its first image chunk ("VP8 " lossy,
     * "VP8L" lossless), so a lossy photo is not inflated either.
     */
    private function isLosslessSource(string $path, int $type): bool
    {
        if ($type === IMAGETYPE_JPEG) {
            return false;
        }

        if ($type !== IMAGETYPE_WEBP) {
            return true;
        }

        $bytes = (string) @file_get_contents($path, false, null, 0, 65536);
        $offset = 12;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $fourcc = substr($bytes, $offset, 4);

            if ($fourcc === 'VP8 ') {
                return false;
            }

            if ($fourcc === 'VP8L') {
                return true;
            }

            /** @var array{1: int} $size */
            $size = unpack('V', substr($bytes, $offset + 4, 4));
            $offset += 8 + $size[1] + ($size[1] % 2);
        }

        // Unreadable layout: lossless never degrades the picture.
        return true;
    }
}
