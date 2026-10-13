<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A domain accepted as a link target on any sheet (SPEC 8): Dolistore,
 * the demo instances, and whatever else the super admin judged harmless.
 * A link on this domain or one of its subdomains raises no domain alert.
 *
 * @property int $id
 * @property string $domain
 * @property int|null $accepted_by_user_id
 * @property Carbon|null $created_at
 */
class AcceptedLinkDomain extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'accepted_by_user_id',
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
     * The account that accepted it.
     *
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
