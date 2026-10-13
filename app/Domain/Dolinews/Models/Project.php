<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A project sheet: the persistent freshmeat-style record (SPEC 4.2, D1).
 *
 * Carries NO Dolibarr compatibility: what is dated lives in the feed.
 *
 * @property int $id
 * @property int $editor_id
 * @property string $slug
 * @property string $name
 * @property string $locale
 * @property string $summary
 * @property string|null $description
 * @property string|null $license
 * @property int|null $logo_media_id
 * @property ProjectStatus $status
 * @property Carbon|null $hidden_at
 */
class Project extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'editor_id',
        'slug',
        'name',
        'locale',
        'summary',
        'description',
        'license',
        'logo_media_id',
        'status',
        'hidden_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'hidden_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * Whether the moderation team has masked the sheet (SPEC 9.3). A
     * masked sheet is out of every public surface; its announcements
     * stay in the feed, filed under no sheet.
     */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Sheets a reader may see: not masked by the moderation team.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('projects.hidden_at');
    }

    /**
     * The editor owning this sheet.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * Typed outgoing links (Dolistore, shop, repo, ...).
     *
     * @return HasMany<ProjectLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class)->orderBy('position');
    }

    /**
     * Text translations of the sheet; the reference version's fields live
     * on the project itself (SPEC D14/4.2).
     *
     * @return HasMany<ProjectTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProjectTranslation::class);
    }

    /**
     * Dated feed entries attached to this project.
     *
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * Test attestations published by the editor for this sheet (SPEC 10).
     *
     * @return HasMany<Attestation, $this>
     */
    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    /**
     * Screenshot gallery, in display order (SPEC 4.2/4.4).
     *
     * @return HasMany<ProjectMedia, $this>
     */
    public function gallery(): HasMany
    {
        return $this->hasMany(ProjectMedia::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Logo media, when one was uploaded.
     *
     * @return BelongsTo<Media, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }
}
