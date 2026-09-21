<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Eloquent\BaseModel;
use Database\Factories\ApiRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single logged API call, used for observability of the public API (S7).
 *
 * DoliNews has no quota middleware: the free public API is rate-limited
 * through named limiters, and every authenticated call lands here so the
 * moderation team can investigate abuse (SPEC 5.2).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class ApiRequest extends BaseModel
{
    /** @use HasFactory<ApiRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'method',
        'path',
        'status',
    ];

    /**
     * Casts with EXPLICIT date formats (S12).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The account that issued the call, when authenticated.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
