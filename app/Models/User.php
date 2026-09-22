<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\EditorWatch;
use App\Domain\Dolinews\Models\ProjectWatch;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Sanctum\HasApiTokens;

/**
 * DoliNews account, covering both reader and contributor classes (SPEC 3.1).
 *
 * Readers register freely and only get watch rights. Contributors carry at
 * least one non-revoked ContributorProof and may write. The moderation team
 * is flagged by is_moderator, the operator overrides by is_super_admin
 * (SPEC 4.1): both are plain columns of the spec's data model, not roles
 * from a role package.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $display_name
 * @property string|null $bio
 * @property string|null $website
 * @property bool $is_moderator
 * @property bool $is_super_admin
 * @property bool $active
 * @property bool $must_change_password
 * @property string|null $feed_token
 * @property EmailDigest $email_digest
 * @property bool $watches_all
 * @property array<int, string>|null $watch_all_focus_filter
 * @property array<int, string>|null $watch_all_maturity_filter
 * @property Carbon|null $digest_cursor_at
 * @property Carbon|null $digest_sent_at
 * @property string|null $unsubscribe_token
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property-read ContributorProof|null $proofs
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Impersonate;
    use Notifiable;

    /**
     * Only the fields an account may write about itself.
     *
     * is_moderator, is_super_admin, active, must_change_password and
     * feed_token are absent on purpose: they are privileges or guards,
     * not profile fields. Nothing fills
     * them from a request today, but this model is the authentication
     * model - one future User::create($request->all()) would be a
     * self-service promotion. They are set explicitly, by the moderation
     * services and the seeder.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'display_name',
        'bio',
        'website',
        'password',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Column defaults the model carries in memory too.
     *
     * A database default only fills the row: a freshly created model
     * still holds null for the column until it is read back, and the
     * account page would then read ->value on nothing.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'email_digest' => 'none',
        'watches_all' => false,
    ];

    /**
     * Casts with EXPLICIT date formats (S12 of the saas-base3 socle).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime:Y-m-d H:i:s',
            'is_moderator' => 'boolean',
            'is_super_admin' => 'boolean',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'email_digest' => EmailDigest::class,
            'watches_all' => 'boolean',
            'watch_all_focus_filter' => 'array',
            'watch_all_maturity_filter' => 'array',
            'digest_cursor_at' => 'datetime:Y-m-d H:i:s',
            'digest_sent_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * Accounts a mail run of this cadence has to consider (SPEC 6.4).
     *
     * Suspended accounts and unverified addresses are left out: a
     * suspension must bite on what is already running, and an address
     * nobody proved is an address someone else typed.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSubscribedTo(Builder $query, EmailDigest $digest): Builder
    {
        return $query->where('active', true)
            ->whereNotNull('email_verified_at')
            ->where('email_digest', $digest->value);
    }

    /**
     * Language of the mails sent to this account (D14).
     *
     * The interface locale lives in the session, which no queued mail
     * and no scheduled command can read: without this column every
     * subscription mail would go out in French.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Contribution proofs owned by this account (SPEC 3.2/4.1).
     *
     * @return HasMany<ContributorProof, $this>
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(ContributorProof::class);
    }

    /**
     * Editors this account belongs to, with the owner/member role (SPEC 4.1).
     *
     * @return BelongsToMany<Editor, $this>
     */
    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(Editor::class, 'editor_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Projects watched by this reader account (SPEC 4.1/6.4).
     *
     * @return HasMany<ProjectWatch, $this>
     */
    public function projectWatches(): HasMany
    {
        return $this->hasMany(ProjectWatch::class);
    }

    /**
     * Editors watched by this reader account (SPEC 4.1/6.4).
     *
     * @return HasMany<EditorWatch, $this>
     */
    public function editorWatches(): HasMany
    {
        return $this->hasMany(EditorWatch::class);
    }

    /**
     * The operator's super admins, suspended accounts left out (SPEC 4.1).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('active', true)->where('is_super_admin', true);
    }

    /**
     * The review team: the moderation team plus the super admin, who sits
     * in the circuit with the override power (SPEC 5.1/9.1).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeReviewTeam(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where(fn (Builder $team) => $team
                ->where('is_moderator', true)
                ->orWhere('is_super_admin', true));
    }

    /**
     * Whether this account may write: it needs at least one verified,
     * non-revoked contribution proof (SPEC 3.1).
     */
    public function isContributor(): bool
    {
        return $this->proofs()
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Whether this account is part of the moderation team (SPEC 9.1).
     */
    public function isModerator(): bool
    {
        return $this->is_moderator && $this->active;
    }

    /**
     * Whether this account may reach the Livewire admin back-office.
     *
     * Moderators and the super admin reach the back-office once their email
     * is verified (SPEC 4.1 flags, socle section 6 gating).
     */
    public function canAccessAdmin(): bool
    {
        if ($this->email_verified_at === null || ! $this->active) {
            return false;
        }

        return $this->is_moderator || $this->is_super_admin;
    }

    /**
     * Only the super admin may start an impersonation (socle policy).
     */
    public function canImpersonate(): bool
    {
        return $this->is_super_admin;
    }

    /**
     * Moderators and the super admin cannot be impersonated (socle policy).
     */
    public function canBeImpersonated(): bool
    {
        return ! $this->is_moderator && ! $this->is_super_admin;
    }
}
