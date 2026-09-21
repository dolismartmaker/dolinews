<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\AttestationConfidence;
use App\Domain\Dolinews\Enums\AttestationSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A test indicator published by the editor for one sheet (SPEC 4.4/10).
 *
 * Never a certification: while instances are hosted by the editors, the
 * ingestion API trusts its caller and the indicator stays declarative.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $article_id
 * @property AttestationSource $source_type
 * @property string $source_url
 * @property AttestationConfidence $confidence
 * @property string $metric
 * @property string $value
 * @property string|null $unit
 * @property Carbon|null $measured_at
 * @property Carbon $received_at
 * @property string|null $signature
 */
class Attestation extends BaseModel
{
    /**
     * The row carries its own times: received_at, and measured_at when
     * the sender knows it. The table has no created_at/updated_at, so
     * Eloquent must not try to write them - it did, and every ingestion
     * died on "no column named updated_at".
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'article_id',
        'source_type',
        'source_url',
        'confidence',
        'metric',
        'value',
        'unit',
        'measured_at',
        'received_at',
        'signature',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => AttestationSource::class,
            'confidence' => AttestationConfidence::class,
            'measured_at' => 'datetime:Y-m-d H:i:s',
            'received_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The sheet this indicator belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Optional announcement the indicator was attached to.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
