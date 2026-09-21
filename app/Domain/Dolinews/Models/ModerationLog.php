<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One moderation act, out of review: hiding, sanction, transfer, quorum
 * override (SPEC 4.5/9.4).
 *
 * Every row names the invoked numbered rule and a motive; the author
 * concerned can read the entries about their content and contest them.
 * requires_confirmation marks conflict-of-interest withdrawals awaiting a
 * second moderator within seven days (SPEC 9.6).
 *
 * @property int $id
 * @property int|null $moderator_user_id
 * @property ModerationAction $action
 * @property int|null $article_id
 * @property int|null $project_id
 * @property int|null $editor_id
 * @property int|null $user_id
 * @property string|null $rule_ref
 * @property string $motive
 * @property bool $requires_confirmation
 * @property int|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property bool $is_legal
 */
class ModerationLog extends BaseModel
{
    // The spec freezes the singular table name (SPEC 4.5): Eloquent's
    // guess would pluralize it into moderation_logs.
    protected $table = 'moderation_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'moderator_user_id',
        'action',
        'article_id',
        'project_id',
        'editor_id',
        'user_id',
        'rule_ref',
        'motive',
        'requires_confirmation',
        'confirmed_by_user_id',
        'confirmed_at',
        'is_legal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ModerationAction::class,
            'requires_confirmation' => 'boolean',
            'confirmed_at' => 'datetime:Y-m-d H:i:s',
            'is_legal' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The moderator who acted; null when the row is the service's own
     * automatic cancellation of an unconfirmed act (SPEC 4.5).
     *
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_user_id');
    }

    /**
     * The moderator who confirmed a conflict-of-interest act, when done.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * The targeted article, when the act concerns one.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * The targeted project sheet, when the act concerns one.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The targeted editor, when the act concerns one.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * The targeted account, when the act concerns one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
