<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\TranslationMandate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Translation mandates (SPEC 5.6): who, outside an editor, may submit a
 * language version of its announcements.
 *
 * Without this delegation, translating is reserved to the author of the
 * announcement and to the members of its editor, which leaves an
 * ecosystem of ten languages to be served by the people least likely to
 * speak them. The mandate is deliberately neutral: it serves a volunteer
 * of the community exactly as it serves a paid translation desk, and the
 * service knows nothing of what was agreed between the two.
 *
 * Only the editor grants and revokes. A moderator does not: this is a
 * relation between an editor and its translator, and revoking the
 * translator's contribution proof already cuts off a translator who
 * abuses the right (SPEC 3.1).
 */
class TranslationMandateService
{
    public function __construct(
        private readonly EditorService $editors,
    ) {}

    /**
     * Delegate the right to translate, or reopen a revoked delegation.
     *
     * @param  array<int, string>|null  $locales  null for every content locale
     *
     * @throws ArticleException when the granter, the translator or the
     *                          scope is refused.
     */
    public function grant(
        Editor $editor,
        User $granter,
        User $translator,
        ?Project $project = null,
        ?array $locales = null,
    ): TranslationMandate {
        if (! $this->editors->isOwner($editor, $granter)) {
            throw new ArticleException(
                'Seul le propriétaire de l\'éditeur peut confier un mandat de traduction.'
            );
        }

        // Translating is writing: the proof of contribution is required
        // here as it is anywhere else (SPEC 3.1).
        if (! $translator->isContributor()) {
            throw new ArticleException(
                'Un mandat de traduction ne se confie qu\'à un compte contributeur vérifié.'
            );
        }

        if ($this->editors->isMember($editor, $translator)) {
            throw new ArticleException(
                'Ce compte est déjà membre de l\'éditeur : il peut traduire sans mandat.'
            );
        }

        if ($project !== null && $project->editor_id !== $editor->getKey()) {
            throw new ArticleException('Cette fiche n\'appartient pas à cet éditeur.');
        }

        $locales = $this->cleanLocales($locales);

        /** @var TranslationMandate $mandate */
        $mandate = TranslationMandate::query()->updateOrCreate(
            [
                'editor_id' => $editor->getKey(),
                'translator_user_id' => $translator->getKey(),
                'project_id' => $project?->getKey(),
            ],
            [
                'locales' => $locales,
                'granted_by_user_id' => $granter->getKey(),
                'granted_at' => now(),
                'revoked_by_user_id' => null,
                'revoked_at' => null,
            ],
        );

        Log::info('TranslationMandateService: mandate granted', [
            'mandate_id' => $mandate->getKey(),
            'editor_id' => $editor->getKey(),
            'translator' => $translator->getKey(),
            'project_id' => $project?->getKey(),
        ]);

        return $mandate;
    }

    /**
     * Withdraw a delegation. The row stays: a revoked mandate is still
     * readable, which is what lets an editor see who held what and when.
     *
     * @throws ArticleException when the account does not own the editor.
     */
    public function revoke(TranslationMandate $mandate, User $revoker): TranslationMandate
    {
        if (! $this->editors->isOwner($mandate->editor, $revoker)) {
            throw new ArticleException(
                'Seul le propriétaire de l\'éditeur peut retirer un mandat de traduction.'
            );
        }

        if (! $mandate->isInForce()) {
            return $mandate;
        }

        $mandate->revoked_by_user_id = $revoker->getKey();
        $mandate->revoked_at = now();
        $mandate->save();

        Log::info('TranslationMandateService: mandate revoked', [
            'mandate_id' => $mandate->getKey(),
            'revoker' => $revoker->getKey(),
        ]);

        return $mandate;
    }

    /**
     * Whether an account holds a mandate covering this editor, project
     * and locale.
     *
     * A null locale asks the weaker question - does this account hold
     * any mandate here - which is what a screen needs to decide whether
     * to offer the translation form at all.
     */
    public function covers(Editor $editor, User $translator, ?int $projectId, ?string $locale = null): bool
    {
        $mandates = TranslationMandate::query()
            ->inForce()
            ->where('editor_id', $editor->getKey())
            ->where('translator_user_id', $translator->getKey())
            ->where(function ($query) use ($projectId): void {
                // A catalogue-wide mandate covers every sheet; a
                // project-scoped one covers its own only.
                $query->whereNull('project_id');

                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->get();

        foreach ($mandates as $mandate) {
            if ($locale === null || $mandate->coversLocale($locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mandates an editor has granted, revoked ones included: an
     * editor reviewing its delegations wants the full history.
     *
     * @return Collection<int, TranslationMandate>
     */
    public function forEditor(Editor $editor)
    {
        return TranslationMandate::query()
            ->where('editor_id', $editor->getKey())
            ->with(['translator', 'project'])
            ->orderByDesc('granted_at')
            ->get();
    }

    /**
     * The mandates in force an account holds, to show a translator what
     * they may work on.
     *
     * @return Collection<int, TranslationMandate>
     */
    public function forTranslator(User $translator)
    {
        return TranslationMandate::query()
            ->inForce()
            ->where('translator_user_id', $translator->getKey())
            ->with(['editor', 'project'])
            ->orderByDesc('granted_at')
            ->get();
    }

    /**
     * Keep the locales the service actually publishes in, and normalise
     * an empty selection to null, which means "every content locale".
     *
     * @param  array<int, string>|null  $locales
     * @return array<int, string>|null
     */
    private function cleanLocales(?array $locales): ?array
    {
        if ($locales === null) {
            return null;
        }

        /** @var array<int, string> $allowed */
        $allowed = (array) config('dolinews.content_locales', []);

        $kept = array_values(array_filter(
            $locales,
            static fn (string $locale): bool => in_array($locale, $allowed, true),
        ));

        return $kept === [] ? null : $kept;
    }
}
