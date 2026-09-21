<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ProofMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The result of a successful contribution verification, attached to an
 * account and revocable (SPEC 3.2/4.1).
 *
 * Kept (with revoked_at set) after the account is deleted: the hash must
 * stay bound so the same git identity cannot register a fresh account
 * (SPEC 3.4/9.8).
 *
 * @property int $id
 * @property int $user_id
 * @property ProofMethod $method
 * @property string $email_hash
 * @property string $source_repo
 * @property int $commit_count
 * @property Carbon $verified_at
 * @property Carbon|null $revoked_at
 */
class ContributorProof extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'method',
        'email_hash',
        'source_repo',
        'commit_count',
        'verified_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => ProofMethod::class,
            'commit_count' => 'integer',
            'verified_at' => 'datetime:Y-m-d H:i:s',
            'revoked_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The account owning this proof.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this proof still grants write rights.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
