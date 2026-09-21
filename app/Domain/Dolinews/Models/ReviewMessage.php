<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Enums\ReviewVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of the private review thread (SPEC 4.5/5.5).
 *
 * Two visibilities in the same thread: author (read by the author and the
 * team) and moderators (internal deliberation). A message carrying a
 * decision is ALWAYS author-visible: the internal channel deliberates, it
 * never decides silently.
 *
 * @property int $id
 * @property int $article_id
 * @property int $user_id
 * @property ReviewVisibility $visibility
 * @property ReviewDecision|null $decision
 * @property string|null $rule_ref
 * @property string $body
 * @property int $submission_seq
 */
class ReviewMessage extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'user_id',
        'visibility',
        'decision',
        'rule_ref',
        'body',
        'submission_seq',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ReviewVisibility::class,
            'decision' => ReviewDecision::class,
            'submission_seq' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The reviewed article.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * The moderator or author who wrote this message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
