<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Support\Carbon;

/**
 * A one-address link mailed to a reader (SPEC 6.4).
 *
 * Two purposes, one table because both answer the same question: does
 * the person holding this address want this?
 *
 *  - confirm: a subscription asked for from a project or editor sheet,
 *    which nothing acts on until the click. The form takes an address
 *    typed by whoever passes by, so the click is what separates a
 *    reader subscribing from a stranger subscribing somebody else;
 *  - manage: the preferences page of an existing account, opened
 *    without a session. The same token motive as the personal feed and
 *    the unsubscribe page, and for the same reason: a subscriber has no
 *    password, and a link that opened a real session would make the
 *    mailbox the single factor of an account that may publish.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property string $purpose
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $expires_at
 * @property Carbon|null $used_at
 */
class SubscriptionLink extends BaseModel
{
    public const PURPOSE_CONFIRM = 'confirm';

    public const PURPOSE_MANAGE = 'manage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'token',
        'purpose',
        'payload',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime:Y-m-d H:i:s',
            'used_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * A link nobody has used yet and whose delay has not run out.
     */
    public function isPending(): bool
    {
        return $this->used_at === null && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
