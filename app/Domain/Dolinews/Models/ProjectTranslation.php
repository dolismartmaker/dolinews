<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text translation of a project sheet (SPEC 4.2, D14).
 *
 * @property int $id
 * @property int $project_id
 * @property string $locale
 * @property string $name
 * @property string $summary
 * @property string|null $description
 */
class ProjectTranslation extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'locale',
        'name',
        'summary',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The reference sheet this text translates.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
