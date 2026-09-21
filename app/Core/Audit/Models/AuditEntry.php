<?php

declare(strict_types=1);

namespace App\Core\Audit\Models;

use App\Core\Eloquent\BaseModel;
use Database\Factories\Core\Audit\AuditEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * A single transverse audit record (S13, section 4 of the socle).
 *
 * Distinct from moderation_log: this table records application events
 * (logins, token creation), while moderation_log records moderation acts
 * with their rule reference and motive (SPEC 4.5/9.4).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditEntry extends BaseModel
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    protected $table = 'core_audit_log';

    /**
     * Bind the factory explicitly: Core models live outside App\Models\*,
     * so the default factory-name guesser cannot resolve them.
     *
     * @return Factory<AuditEntry>
     */
    protected static function newFactory(): Factory
    {
        return AuditEntryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }
}
