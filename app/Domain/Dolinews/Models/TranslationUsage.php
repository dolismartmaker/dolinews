<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one editor spent of the shared translation route in one month
 * (SPEC 5.7).
 *
 * It counts characters sent, not money: the route is free, and the
 * ceiling exists to share a common resource between editors, not to sell
 * anything. An editor translating on its own key is counted nowhere.
 *
 * @property int $id
 * @property int $editor_id
 * @property string $period YYYY-MM
 * @property int $characters
 * @property-read Editor $editor
 */
class TranslationUsage extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'editor_id',
        'period',
        'characters',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'characters' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }
}
