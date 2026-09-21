<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader account following one project (SPEC 4.1/6.4).
 *
 * Null filters mean the site defaults apply; a watch usually narrows to
 * what the integrator actually follows, security fixes first.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property array<int, string>|null $focus_filter
 * @property array<int, string>|null $maturity_filter
 */
class ProjectWatch extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
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
     * The followed project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Focus values this watch keeps, null meaning "site default".
     *
     * @return list<Focus>
     */
    public function focusValues(): array
    {
        return array_values(array_map(
            static fn (string $value): Focus => Focus::from($value),
            $this->focus_filter ?? [],
        ));
    }

    /**
     * Maturity values this watch keeps, null meaning "site default".
     *
     * @return list<Maturity>
     */
    public function maturityValues(): array
    {
        return array_values(array_map(
            static fn (string $value): Maturity => Maturity::from($value),
            $this->maturity_filter ?? [],
        ));
    }
}
