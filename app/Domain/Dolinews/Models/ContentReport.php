<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Enums\ReportStatus;
use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One report of a published content to the moderation team (SPEC 9.9).
 *
 * The review happens before publication (D6), which does not make it
 * infallible: a content validated too fast stays online until someone
 * says so, and the reader who sees it has no other way in. The operator
 * is the editor of the validated contents (SPEC 9.7), so being reachable
 * is part of the responsibility, not a courtesy.
 *
 * It is not a public comment (D10): the report is read by the team and
 * by nobody else, and the reported author never learns who filed it.
 *
 * @property int $id
 * @property int|null $article_id
 * @property int|null $project_id
 * @property ReportReason $reason
 * @property string $body
 * @property string $reporter_email
 * @property int|null $reporter_user_id
 * @property string $locale
 * @property ReportStatus $status
 * @property int|null $handled_by_user_id
 * @property Carbon|null $handled_at
 * @property string|null $resolution
 * @property Carbon|null $created_at
 * @property-read Article|null $article
 * @property-read Project|null $project
 */
class ContentReport extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'project_id',
        'reason',
        'body',
        'reporter_email',
        'reporter_user_id',
        'locale',
        'status',
        'handled_by_user_id',
        'handled_at',
        'resolution',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'handled_at' => 'datetime:Y-m-d H:i:s',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * The reported article, when the report targets one.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * The reported project sheet, when the report targets one.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The account behind the report, when the reporter was logged in.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /**
     * The moderator who closed the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /**
     * Reports still awaiting a decision.
     *
     * @param  Builder<ContentReport>  $query
     * @return Builder<ContentReport>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::OPEN->value);
    }

    /**
     * What was reported, in one line, for the queue screen and the mail.
     *
     * A report always has a target: the column pair is filled by the
     * service, never by a request. The fallbacks cover the row whose
     * target went away between the listing and the rendering, and the
     * last line a model built in memory and never saved.
     */
    public function targetLabel(): string
    {
        if ($this->article_id !== null) {
            return __('Article').' : '.($this->article->title ?? '#'.$this->article_id);
        }

        if ($this->project_id !== null) {
            return __('Fiche projet').' : '.($this->project->name ?? '#'.$this->project_id);
        }

        return __('Cible supprimée');
    }

    /**
     * Public address of what was reported, so a moderator reads the
     * content before deciding anything about it.
     */
    public function targetUrl(): ?string
    {
        if ($this->article_id !== null && $this->article !== null) {
            return ArticleUrl::for($this->article);
        }

        if ($this->project_id !== null && $this->project !== null) {
            return route('projects.show', $this->project->slug);
        }

        return null;
    }
}
