<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A media file: always re-encoded at intake, SVG refused, EXIF stripped
 * by the rewrite itself (SPEC 7, D7).
 *
 * article_id is null until the referencing article is created: the
 * two-step illustrated publication uploads media first (SPEC 5.2), and a
 * periodic task purges, beyond twenty-four hours, the media nothing
 * references - neither an article nor a sheet (logo, gallery,
 * description) nor an editor logo.
 *
 * @property int $id
 * @property int|null $editor_id
 * @property int|null $article_id
 * @property string $path
 * @property string $mime
 * @property int|null $width
 * @property int|null $height
 * @property int $bytes
 * @property string $hash
 * @property string|null $source_hash
 * @property string|null $alt
 */
class Media extends BaseModel
{
    public const DISK = 'media';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'editor_id',
        'article_id',
        'path',
        'mime',
        'width',
        'height',
        'bytes',
        'hash',
        'source_hash',
        'alt',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The editor who uploaded this file.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * The article referencing this file, once bound.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Absolute URL of the re-encoded file on the public media disk.
     */
    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    /**
     * The same URL, absolute: the disk yields a root-relative path under
     * some configurations, and a feed reader or an API client has no page
     * to resolve it against.
     */
    public function absoluteUrl(): string
    {
        return url($this->url());
    }

    /**
     * The shape an image travels in, in the API and in the JSON feed: the
     * dimensions let a client reserve the space before the file arrives.
     *
     * @return array{url: string, width: int, height: int, alt: string|null}
     */
    public function imagePayload(): array
    {
        return [
            'url' => $this->absoluteUrl(),
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'alt' => $this->alt,
        ];
    }
}
