<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proposed modification of a published article (SPEC 5.4).
 *
 * payload holds the changed fields only, snapshot the COMPLETE article
 * state before application: rebuilding an original by replaying three
 * diffs backwards fails at the first mistake, and the past is exactly
 * what is contested when it is needed.
 *
 * @property int $id
 * @property int $article_id
 * @property int $author_user_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $snapshot
 * @property string $motive
 * @property string $status pending|applied|rejected
 * @property Carbon|null $decided_at
 */
class ArticleRevision extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'author_user_id',
        'payload',
        'snapshot',
        'motive',
        'status',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'snapshot' => 'array',
            'decided_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The modified article.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * The author of the proposed modification.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Whether this revision is still awaiting a decision. Only one pending
     * revision may exist per article at a time (SPEC 5.4).
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
