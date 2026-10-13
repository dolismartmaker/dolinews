<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Models;

use App\Core\Eloquent\BaseModel;
use App\Domain\Dolinews\Projects\LinkPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An editor: the organisation or person who publishes (SPEC 4.1).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $website
 * @property string|null $website_host
 * @property string $contact_email
 * @property int|null $logo_media_id
 * @property Carbon|null $verified_at
 * @property bool $auto_translate
 * @property array<int, string>|null $translation_locales
 * @property string|null $translation_api_key
 * @property Carbon|null $translation_key_set_at
 * @property int|null $translation_monthly_characters
 */
class Editor extends BaseModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'website',
        'contact_email',
        'logo_media_id',
        'verified_at',
        'auto_translate',
        'translation_locales',
        'translation_api_key',
        'translation_key_set_at',
    ];

    /**
     * The normalized host follows the website, whoever writes it - the
     * account, the API or the back-office: the links of the editor's
     * sheets are compared to it (SPEC 8).
     */
    protected static function booted(): void
    {
        static::saving(static function (self $editor): void {
            $editor->website_host = LinkPolicy::hostOf($editor->website);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime:Y-m-d H:i:s',
            'auto_translate' => 'boolean',
            // Null means every language the service offers, the state an
            // editor starts in (SPEC 5.7).
            'translation_locales' => 'array',
            // Encrypted at rest: it is a third party's credential, and
            // a dump of the table must not hand it over (SPEC 5.7).
            'translation_api_key' => 'encrypted',
            'translation_key_set_at' => 'datetime:Y-m-d H:i:s',
            // Left out of $fillable: only the super admin sets it, from
            // the back-office (SPEC 5.7). Null means the default ceiling.
            'translation_monthly_characters' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * Accounts belonging to this editor, with the owner/member role.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'editor_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Project sheets owned by this editor.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Articles published by this editor.
     *
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
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
