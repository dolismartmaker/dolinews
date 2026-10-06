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
 * @property bool $auto_translated
 * @property string|null $source_fingerprint
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
        'auto_translated',
        'source_fingerprint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_translated' => 'boolean',
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
