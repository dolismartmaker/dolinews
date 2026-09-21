<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Support\Carbon;

/**
 * Technical key/value store for one-way service-state facts (see the
 * service_state migration): bootstrap-phase closure (SPEC 5.1) and any
 * later flag that must survive its triggering condition.
 *
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $updated_at
 */
class ServiceState extends BaseModel
{
    // Singular table name (technical store): keep it distinct from
    // Eloquent's pluralized guess.
    protected $table = 'service_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** No created_at column: the store tracks updated_at only. */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * Read a state value; the null default means "never written".
     */
    public static function read(string $key): ?string
    {
        /** @var self|null $state */
        $state = static::query()->find($key);

        return $state?->value;
    }

    /**
     * Write a state value once: existing rows are never overwritten, the
     * facts stored here are one-way by design.
     */
    public static function writeOnce(string $key, string $value): void
    {
        static::query()->firstOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }
}
