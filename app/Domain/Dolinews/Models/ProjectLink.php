<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Projects\LinkPolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A typed outgoing link of a project sheet (SPEC 4.2/8).
 *
 * A table and not columns because their number will grow; external_id
 * receives the Dolistore sheet id when extractible from the URL, and the
 * (type, external_id) unique constraint detects claim conflicts (SPEC 9.5).
 *
 * @property int $id
 * @property int $project_id
 * @property LinkType $type
 * @property string $url
 * @property string|null $host
 * @property string|null $label
 * @property int $position
 * @property string|null $external_id
 * @property Carbon|null $checked_at
 * @property bool $is_broken
 */
class ProjectLink extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'type',
        'url',
        'label',
        'position',
        'external_id',
        'checked_at',
        'is_broken',
    ];

    /**
     * The normalized host follows the URL, whoever writes it: the domain
     * alert of the back-office is counted on it (SPEC 8).
     */
    protected static function booted(): void
    {
        static::saving(static function (self $link): void {
            $link->host = LinkPolicy::hostOf($link->url);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LinkType::class,
            'position' => 'integer',
            'checked_at' => 'datetime:Y-m-d H:i:s',
            'is_broken' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The sheet this link belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
