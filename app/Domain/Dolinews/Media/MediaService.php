<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Media;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Media intake (SPEC 7, D7): an upload is an attack vector, declared
 * MIME type and extension are never proofs.
 *
 * Every image is fully re-encoded: the rewrite produces a clean file,
 * neutralizes polyglot files, and drops EXIF metadata as a side effect.
 * SVG is refused: the format embeds script. Identical files (same sha256
 * after re-encoding) deduplicate per editor.
 */
class MediaService
{
    /** Re-encode targets by detected source format. */
    private const OUTPUT_PNG = 'image/png';

    private const OUTPUT_JPEG = 'image/jpeg';

    public function __construct()
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension is required for media re-encoding.');
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

        $image = $this->decode($file->getRealPath(), $type);

        if ($image === null) {
            throw new MediaException('Le fichier n\'est pas une image bitmap lisible.');
        }

        try {
            $final = $this->rescale($image, $width, $height) ?? $image;

            try {
                [$binary, $outMime] = $this->encode($final, $type);
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
                    $existing->save();
                }

                return $existing;
            }

            $path = $editor->getKey().'/'.substr($hash, 0, 2).'/'.$hash.$this->extensionFor($outMime);

            Storage::disk(Media::DISK)->put($path, $binary);

            return Media::query()->create([
                'editor_id' => $editor->getKey(),
                'article_id' => null,
                'path' => $path,
                'mime' => $outMime,
                'width' => $outWidth,
                'height' => $outHeight,
                'bytes' => strlen($binary),
                'hash' => $hash,
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
     * but never bound to an article.
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
            ->get();

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
     * Decode a bitmap of the given GD constant type.
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

        return $image instanceof \GdImage ? $image : null;
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

        // Preserve alpha for PNG/WebP sources.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    /**
     * Re-encode the image: the full rewrite is the point (D7), it drops
     * every metadata block and neutralizes polyglots.
     *
     * @return array{0: string, 1: string} binary content and target MIME
     */
    private function encode(\GdImage $image, int $sourceType): array
    {
        // Photographic sources stay JPEG (quality 85), everything else
        // goes PNG: screenshots stay crisp, files stay reasonable.
        $target = $sourceType === IMAGETYPE_JPEG ? self::OUTPUT_JPEG : self::OUTPUT_PNG;

        ob_start();

        if ($target === self::OUTPUT_JPEG) {
            // White flatten for JPEG (no alpha channel in the format).
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));

            if ($flat !== false) {
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefilledrectangle($flat, 0, 0, imagesx($image), imagesy($image), $white ? $white : 0);
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagejpeg($flat, null, 85);
                imagedestroy($flat);
            } else {
                imagejpeg($image, null, 85);
            }
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        }

        /** @var string $binary */
        $binary = ob_get_clean();

        return [$binary, $target];
    }

    private function extensionFor(string $mime): string
    {
        return $mime === self::OUTPUT_JPEG ? '.jpg' : '.png';
    }
}
