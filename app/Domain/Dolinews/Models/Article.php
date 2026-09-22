<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ArticleType;
use App\Domain\Dolinews\Enums\CompatStatus;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One dated feed entry: a release or an announcement (SPEC 4.3).
 *
 * Translations of one announcement are separate articles linked by
 * translation_group_id; the group id is assigned at creation of every
 * article, even a lone original (SPEC 4.3, D14).
 *
 * @property int $id
 * @property int $editor_id
 * @property int|null $project_id
 * @property int|null $author_user_id
 * @property ArticleType $type
 * @property Focus|null $focus
 * @property string $title
 * @property string $slug
 * @property string|null $version
 * @property string $summary
 * @property string $body
 * @property string $locale
 * @property string $translation_group_id
 * @property bool $is_source
 * @property int $revision_number
 * @property int|null $source_revision_number
 * @property int|null $dolibarr_min
 * @property int|null $dolibarr_max
 * @property Maturity $maturity
 * @property CompatStatus $compat_status
 * @property ArticleStatus $status
 * @property PublicationMode|null $publication_mode
 * @property Carbon|null $submitted_at
 * @property int $submission_seq
 * @property Carbon|null $published_at
 * @property Carbon|null $deleted_at
 * @property-read Editor $editor
 * @property-read Project|null $project
 * @property-read User|null $author
 */
class Article extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'editor_id',
        'project_id',
        'author_user_id',
        'type',
        'focus',
        'title',
        'slug',
        'version',
        'summary',
        'body',
        'locale',
        'translation_group_id',
        'is_source',
        'revision_number',
        'source_revision_number',
        'dolibarr_min',
        'dolibarr_max',
        'maturity',
        'compat_status',
        'status',
        'publication_mode',
        'submitted_at',
        'submission_seq',
        'published_at',
        'deleted_at',
    ];

    /**
     * Casts with EXPLICIT date formats (S12) and typed enums.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArticleType::class,
            'focus' => Focus::class,
            'is_source' => 'boolean',
            'revision_number' => 'integer',
            'source_revision_number' => 'integer',
            'dolibarr_min' => 'integer',
            'dolibarr_max' => 'integer',
            'maturity' => Maturity::class,
            'compat_status' => CompatStatus::class,
            'status' => ArticleStatus::class,
            'publication_mode' => PublicationMode::class,
            'submitted_at' => 'datetime:Y-m-d H:i:s',
            'submission_seq' => 'integer',
            'published_at' => 'datetime:Y-m-d H:i:s',
            'deleted_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The publishing editor.
     *
     * @return BelongsTo<Editor, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Editor::class);
    }

    /**
     * The project this release belongs to (null on announcements).
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The author (null once a deleted account's articles were minimized).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * The private review thread attached to this article (SPEC 5.5).
     *
     * @return HasMany<ReviewMessage, $this>
     */
    public function reviewMessages(): HasMany
    {
        return $this->hasMany(ReviewMessage::class)->orderBy('created_at');
    }

    /**
     * The re-encoded images bound to this article (SPEC 5.2/7).
     *
     * Only media the body may show: an upload stays orphan until the
     * referencing article is created, and a periodic task purges what was
     * never bound.
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('id');
    }

    /**
     * Post-publication revisions (SPEC 5.4).
     *
     * @return HasMany<ArticleRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class)->orderByDesc('created_at');
    }

    /**
     * All language versions of this announcement, this one included (D14).
     *
     * @return HasMany<Article, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Article::class, 'translation_group_id', 'translation_group_id');
    }

    /**
     * Scope: published and not soft-deleted (the public feed).
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::PUBLISHED)
            ->whereNull('deleted_at');
    }

    /**
     * Scope: awaiting review, security first, oldest first (SPEC 5.1).
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeReviewQueue(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::PENDING)
            ->whereNull('deleted_at')
            ->orderByRaw("focus = 'security' DESC")
            ->orderBy('submitted_at');
    }

    /**
     * Whether this article is a translation (not the group's source).
     */
    public function isTranslation(): bool
    {
        return ! $this->is_source;
    }

    /**
     * Whether this article was published under a date preceding its own
     * submission (SPEC 5.1).
     *
     * Derived rather than stored: an article published before it was
     * submitted can only have been back-dated, so the state cannot drift
     * from a flag someone forgot to set. The cost is that back-dating to
     * a moment AFTER the submission goes undetected, which no use of the
     * derogation produces -- it exists to carry a version released years
     * before the service did.
     *
     * Three consequences hang on this, and all three would misreport
     * without it: the public review delay (ReviewStats), the publication
     * token bucket (PublicationQuotaService), and the durable public
     * mention the article carries.
     */
    public function isBackdated(): bool
    {
        return $this->published_at !== null
            && $this->submitted_at !== null
            && $this->published_at->lessThan($this->submitted_at);
    }

    /**
     * Published articles whose publication date precedes their
     * submission, and the reverse. Kept next to the accessor so the two
     * definitions cannot part ways.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeBackdated(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->whereNotNull('submitted_at')
            ->whereColumn('published_at', '<', 'submitted_at');
    }

    /**
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeNotBackdated(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('published_at')
                ->orWhereNull('submitted_at')
                ->orWhereColumn('published_at', '>=', 'submitted_at');
        });
    }

    /**
     * The announced Dolibarr floor, or null when the value is the one the
     * module builder writes into every descriptor it generates.
     *
     * The catalogue script reads need_dolibarr_version straight from the
     * descriptor, and most authors never touch it: a floor equal to the
     * generator's default says nothing about the announcement, so it is
     * not shown. Only the floor is filtered this way -- a ceiling is
     * never a default, it is always typed by hand.
     *
     * Kept out of the views because both the feed and the article page
     * ask the question, and the answer has to be the same on both.
     */
    public function announcedDolibarrMin(): ?int
    {
        if ($this->dolibarr_min === null) {
            return null;
        }

        $default = (int) config('dolinews.dolibarr_generator_default_min');

        return $this->dolibarr_min === $default ? null : $this->dolibarr_min;
    }

    /**
     * Whether this translation was written against an older source text:
     * the source's revision_number has moved past the revision this
     * translation was based on (SPEC 5.4). Always false on the source.
     */
    public function isStaleTranslation(): bool
    {
        if ($this->is_source || $this->source_revision_number === null) {
            return false;
        }

        $source = Article::query()
            ->where('translation_group_id', $this->translation_group_id)
            ->where('is_source', true)
            ->first();

        return $source !== null && $source->revision_number !== $this->source_revision_number;
    }

    /**
     * The source article of this translation group, null when this IS the
     * source.
     */
    public function sourceArticle(): ?self
    {
        if ($this->is_source) {
            return null;
        }

        /** @var self|null $source */
        $source = Article::query()
            ->where('translation_group_id', $this->translation_group_id)
            ->where('is_source', true)
            ->first();

        return $source;
    }

    /**
     * The last applied correction, exposed for the "corrected on DATE,
     * motive" mention (SPEC 5.4); null when never corrected.
     */
    public function lastAppliedRevision(): ?ArticleRevision
    {
        return $this->revisions()
            ->where('status', 'applied')
            ->latest('decided_at')
            ->first();
    }
}
