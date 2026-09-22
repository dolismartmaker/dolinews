<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An editor's delegation of the right to translate its announcements
 * (SPEC 5.6).
 *
 * Neutral by construction: the same row serves a volunteer of the
 * community and a paid translation desk. The service knows nothing of
 * what was agreed between the two, and nothing of money -- billing is
 * out of scope (SPEC 13).
 *
 * @property int $id
 * @property int $editor_id
 * @property int $translator_user_id
 * @property int|null $project_id
 * @property array<int, string>|null $locales
 * @property int $granted_by_user_id
 * @property Carbon $granted_at
 * @property int|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 * @property-read Editor $editor
 * @property-read User $translator
 * @property-read Project|null $project
 */
class TranslationMandate extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'editor_id',
        'translator_user_id',
        'project_id',
        'locales',
        'granted_by_user_id',
        'granted_at',
        'revoked_by_user_id',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'granted_at' => 'datetime:Y-m-d H:i:s',
            'revoked_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The editor whose announcements may be translated.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * The account the right is delegated to.
     *
     * @return BelongsTo<User, $this>
     */
    public function translator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translator_user_id');
    }

    /**
     * The project the mandate is narrowed to, null for the whole
     * catalogue of the editor.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope: mandates still in force.
     *
     * @param  Builder<TranslationMandate>  $query
     * @return Builder<TranslationMandate>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Whether this mandate is in force right now.
     */
    public function isInForce(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Whether this mandate covers a locale. A null locale list means
     * every content locale of the service, today's and tomorrow's: an
     * editor handing over its catalogue should not have to re-grant the
     * day a tenth language opens.
     */
    public function coversLocale(string $locale): bool
    {
        if ($this->locales === null || $this->locales === []) {
            return true;
        }

        return in_array($locale, $this->locales, true);
    }
}
