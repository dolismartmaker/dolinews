<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;

/**
 * Fingerprint of an OpenPGP key observed signing commits of one commit
 * address in one reference repository (SPEC 3.2, strong level).
 *
 * The strong proof binds a candidate to the key recorded here, never to
 * a key the candidate supplies: an address without signed commits has
 * no GPG path and goes through manual validation.
 *
 * @property int $id
 * @property string $email_hash
 * @property string $source_repo
 * @property string $key_fingerprint uppercase hexadecimal
 */
class KnownCommitterKey extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_hash',
        'source_repo',
        'key_fingerprint',
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
}
