<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader account following one whole editor (SPEC 4.1/6.4): an
 * integrator deploys a whole catalogue and must not miss the sixteenth
 * sheet's release day.
 *
 * @property int $id
 * @property int $user_id
 * @property int $editor_id
 * @property array<int, string>|null $focus_filter
 * @property array<int, string>|null $maturity_filter
 */
class EditorWatch extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'editor_id',
        'focus_filter',
        'maturity_filter',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'focus_filter' => 'array',
            'maturity_filter' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The reading account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The followed editor.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }
}
