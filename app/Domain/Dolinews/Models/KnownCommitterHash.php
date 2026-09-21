<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One commit-address hash observed in one reference repository
 * (SPEC 3.2/4.1).
 *
 * Observation data, alimented by the periodic harvest, with no link to
 * any account: this table is the index a contributor application is
 * searched in. The clear address is never stored, only
 * sha256(pepper || normalised address).
 *
 * @property int $id
 * @property string $email_hash
 * @property string $source_repo
 * @property int $commit_count
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class KnownCommitterHash extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_hash',
        'source_repo',
        'commit_count',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commit_count' => 'integer',
            'first_seen_at' => 'datetime:Y-m-d H:i:s',
            'last_seen_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }
}
