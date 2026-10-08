<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image of a sheet gallery (SPEC 4.2/4.4): a medium of the sheet's
 * editor, its place in the list and an optional caption.
 *
 * @property int $id
 * @property int $project_id
 * @property int $media_id
 * @property int $position
 * @property string|null $caption
 */
class ProjectMedia extends BaseModel
{
    protected $table = 'project_media';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'media_id',
        'position',
        'caption',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The sheet this image illustrates.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The re-encoded file.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
