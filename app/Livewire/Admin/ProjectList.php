<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\AcceptedLinkDomain;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Domain\Dolinews\Projects\LinkPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Project sheet list (thin). Also surfaces the outgoing-link domain
 * watch (SPEC 8): a link whose domain differs from the editor's
 * declared domain is signalled here for the moderation team, and the
 * column sorts so the flagged sheets come first.
 *
 * The act panel opens a sheet for the team: what it says, where its
 * links go, and the acts the team holds on it (SPEC 9.1). Masking is a
 * withdrawal act, journalled with rule and motive and never blocked by a
 * conflict of interest (SPEC 9.6). Transferring a sheet to another
 * editor settles a claim (SPEC 9.5) and is reserved to the super admin.
 * Nobody here rewrites the sheet: it is the editor's text, the team
 * masks and the editor corrects.
 *
 * @extends AdminList<Project>
 */
class ProjectList extends AdminList
{
    public ?int $actProjectId = null;

    public string $actKind = 'hide';

    public string $actMotive = '';

    public string $actRule = '';

    public bool $actConflict = false;

    public bool $actLegal = false;

    /**
     * Transfer form state: the receiving editor, the motive, and the
     * rule when the transfer sanctions a usurpation (SPEC 9.5).
     */
    public string $transferEditorId = '';

    public string $transferMotive = '';

    public string $transferRule = '';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Project>
     */
    protected function baseQuery(): Builder
    {
        // Links off the editor's domain, counted in SQL so the column
        // sorts (SPEC 8). Same rule as LinkPolicy::matchesEditorDomain():
        // the declared host or one of its subdomains, an editor without a
        // website raising nothing, Dolistore and forge links never compared,
        // and the domains accepted for every sheet left aside.
        $concat = static fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "CONCAT('%.', {$column})"
            : "'%.' || {$column}";
        $suffix = $concat('editors.website_host');

        $offDomain = DB::table('project_links')
            ->join('editors', 'editors.id', '=', 'projects.editor_id')
            ->selectRaw('count(*)')
            ->whereColumn('project_links.project_id', 'projects.id')
            ->whereIn('project_links.type', LinkType::editorDomainValues())
            ->whereNotNull('editors.website_host')
            ->where(static function (QueryBuilder $mismatch) use ($suffix, $concat): void {
                $mismatch->whereNull('project_links.host')
                    ->orWhere(static function (QueryBuilder $elsewhere) use ($suffix, $concat): void {
                        $elsewhere->whereColumn('project_links.host', '!=', 'editors.website_host')
                            ->whereRaw('project_links.host NOT LIKE '.$suffix)
                            ->whereNotExists(static function (QueryBuilder $accepted) use ($concat): void {
                                $accepted->selectRaw('1')
                                    ->from('accepted_link_domains')
                                    ->where(static function (QueryBuilder $match) use ($concat): void {
                                        $match->whereColumn('project_links.host', 'accepted_link_domains.domain')
                                            ->orWhereRaw('project_links.host LIKE '.$concat('accepted_link_domains.domain'));
                                    });
                            });
                    });
            });

        return Project::query()
            ->select('projects.*')
            ->selectSub($offDomain, 'domain_alert')
            ->with(['editor']);
    }

    public function heading(): string
    {
        return __('Fiches projet');
    }

    public function intro(): string
    {
        return __('La fiche décrit le projet et ne vieillit pas : elle ne porte aucune compatibilité Dolibarr, qui vit sur l\'article avec sa date.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'slug', 'label' => __('Slug'), 'sortable' => true, 'searchable' => true],
            ['key' => 'name', 'label' => __('Nom'), 'sortable' => true, 'searchable' => true],
            ['key' => 'status', 'label' => __('Statut'), 'sortable' => true, 'searchable' => true],
            ['key' => 'domain_alert', 'label' => __('Alerte domaine'), 'sortable' => true, 'searchable' => false],
            ['key' => 'hidden_at', 'label' => __('Masquée le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    public function actions(): array
    {
        return [
            ['label' => __('Ouvrir'), 'method' => 'openAct'],
        ];
    }

    public function panelView(): ?string
    {
        return 'livewire.admin.partials.project-act';
    }

    /**
     * The sheet the open panel shows, null when none is open.
     */
    public function actTarget(): ?Project
    {
        if ($this->actProjectId === null) {
            return null;
        }

        return Project::query()
            ->with(['editor', 'links'])
            ->find($this->actProjectId);
    }

    /**
     * Whether a link of the open sheet is off its editor's domain, the
     * same rule as the list column (SPEC 8).
     */
    public function isOffDomain(int $linkId): bool
    {
        $project = $this->actTarget();
        $link = $project?->links->firstWhere('id', $linkId);

        if ($project === null || $link === null || $project->editor === null) {
            return false;
        }

        return ! app(LinkPolicy::class)->matchesEditorDomain($link->url, $project->editor, $link->type, $this->acceptedDomainNames());
    }

    /**
     * The domains accepted for every sheet, newest first (SPEC 8).
     *
     * @return Collection<int, AcceptedLinkDomain>
     */
    public function acceptedDomains(): Collection
    {
        /** @var Collection<int, AcceptedLinkDomain> $domains */
        $domains = AcceptedLinkDomain::query()->orderBy('domain')->get();

        return $domains;
    }

    /**
     * @return list<string>
     */
    private function acceptedDomainNames(): array
    {
        return array_values(array_map(
            static fn (AcceptedLinkDomain $domain): string => $domain->domain,
            $this->acceptedDomains()->all(),
        ));
    }

    /**
     * The levels a flagged link of the open sheet may be accepted at, from
     * its host to its last two labels: the super admin picks how wide.
     *
     * @return list<string>
     */
    public function acceptableLevels(int $linkId): array
    {
        $link = $this->actTarget()?->links->firstWhere('id', $linkId);

        if ($link === null || $link->host === null) {
            return [];
        }

        return LinkPolicy::domainLevels($link->host);
    }

    /**
     * Accept a domain for every sheet (SPEC 8). Reserved to the super
     * admin, and only at a level of a link the open sheet flags: the list
     * grows from what was actually seen, never from free input - the
     * browser sends the level's index, the domain is recomputed here. An
     * exploitation setting, not a moderation act: the transverse audit
     * carries it.
     */
    public function acceptDomain(int $linkId, int $level): void
    {
        $actor = $this->requireSuperAdmin('link_domain.accept');

        if ($actor === null) {
            return;
        }

        $domain = $this->acceptableLevels($linkId)[$level] ?? null;

        if ($domain === null || ! $this->isOffDomain($linkId)) {
            Log::warning('ProjectList: domain acceptance refused', [
                'project_id' => $this->actProjectId,
                'link_id' => $linkId,
                'level' => $level,
            ]);
            $this->error(__('Ce domaine ne correspond à aucun lien hors domaine de cette fiche.'));

            return;
        }

        $accepted = AcceptedLinkDomain::query()->firstOrCreate(
            ['domain' => $domain],
            ['accepted_by_user_id' => $actor->getKey()],
        );

        app(AuditLogger::class)->log('link_domain.accepted', $accepted, [
            'domain' => $domain,
            'project_id' => $this->actProjectId,
            'link_id' => $linkId,
        ]);

        $this->success(__('Domaine :domain accepté pour toutes les fiches.', ['domain' => $domain]));
    }

    /**
     * Take a domain off the list: its links count again in the alert.
     */
    public function removeDomain(int $domainId): void
    {
        $actor = $this->requireSuperAdmin('link_domain.remove');

        if ($actor === null) {
            return;
        }

        $accepted = AcceptedLinkDomain::query()->findOrFail($domainId);

        app(AuditLogger::class)->log('link_domain.removed', $accepted, ['domain' => $accepted->domain]);
        $accepted->delete();

        $this->success(__('Domaine :domain retiré de la liste.', ['domain' => $accepted->domain]));
    }

    /**
     * The super admin acting, or null once the refusal of an impersonation
     * has been shown: these settings change the service for every sheet.
     */
    private function requireSuperAdmin(string $act): ?User
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        if (($refusal = $this->impersonationRefusal($act)) !== null) {
            $this->error($refusal);

            return null;
        }

        return $actor;
    }

    /**
     * The editors the open sheet may be transferred to: every one but
     * its current owner.
     *
     * @return Collection<int, Editor>
     */
    public function transferTargets(): Collection
    {
        $project = $this->actTarget();

        /** @var Collection<int, Editor> $editors */
        $editors = Editor::query()
            ->when($project !== null, static fn (Builder $query) => $query->whereKeyNot($project?->editor_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $editors;
    }

    /**
     * Open the panel on one sheet.
     */
    public function openAct(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);

        $this->actProjectId = (int) $project->getKey();
        $this->actKind = $project->isHidden() ? 'unhide' : 'hide';
        $this->actMotive = '';
        $this->actRule = '';
        $this->actConflict = false;
        $this->actLegal = false;
        $this->transferEditorId = '';
        $this->transferMotive = '';
        $this->transferRule = '';
        $this->resetErrorBag();
    }

    /**
     * Close the panel without acting.
     */
    public function closeAct(): void
    {
        $this->actProjectId = null;
        $this->resetErrorBag();
    }

    /**
     * Mask the sheet or give it back. A withdrawal goes through whatever
     * the conflict of interest: urgency first (SPEC 9.6).
     */
    public function applyAct(ModerationService $moderation): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && ($actor->isModerator() || $actor->is_super_admin), 403);

        if (($refusal = $this->impersonationRefusal('project.act')) !== null) {
            $this->error($refusal);

            return;
        }

        $this->validate([
            'actMotive' => ['required', 'string', 'min:10', 'max:2000'],
            'actRule' => ['required', 'string', 'max:20'],
            'actKind' => ['required', 'in:hide,unhide'],
        ]);

        $project = Project::query()->findOrFail($this->actProjectId);

        try {
            if ($this->actKind === 'hide') {
                $moderation->hideProject($project, $actor, $this->actMotive, $this->actRule, $this->actConflict, $this->actLegal);
            } else {
                $moderation->unhideProject($project, $actor, $this->actMotive, $this->actRule);
            }
        } catch (ModerationException $e) {
            Log::warning('ProjectList: moderation act refused', [
                'project_id' => $this->actProjectId,
                'kind' => $this->actKind,
                'reason' => $e->getMessage(),
            ]);
            $this->addError('actMotive', $e->getMessage());

            return;
        }

        $this->actProjectId = null;
        $this->success(__('Acte appliqué et journalisé.'));
    }

    /**
     * Transfer the open sheet to another editor (SPEC 9.5). Reserved to
     * the super admin: it touches who owns what, like the acts on an
     * account (SPEC 9.1). The service refuses it to a member of either
     * side (SPEC 9.6).
     */
    public function transfer(ModerationService $moderation): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        if (($refusal = $this->impersonationRefusal('project.transfer')) !== null) {
            $this->error($refusal);

            return;
        }

        $this->validate([
            'transferEditorId' => ['required', 'integer', 'exists:editors,id'],
            'transferMotive' => ['required', 'string', 'min:10', 'max:2000'],
            'transferRule' => ['nullable', 'string', 'max:20'],
        ]);

        $project = Project::query()->findOrFail($this->actProjectId);
        $toEditor = Editor::query()->findOrFail((int) $this->transferEditorId);

        try {
            $moderation->transferProject(
                $project,
                $toEditor,
                $actor,
                $this->transferMotive,
                $this->transferRule === '' ? null : $this->transferRule,
            );
        } catch (ModerationException $e) {
            Log::warning('ProjectList: transfer refused', [
                'project_id' => $this->actProjectId,
                'to_editor' => $this->transferEditorId,
                'reason' => $e->getMessage(),
            ]);
            $this->addError('transferEditorId', $e->getMessage());

            return;
        }

        $this->actProjectId = null;
        $this->success(__('Fiche transférée, acte journalisé.'));
    }

    /**
     * Domain-mismatch signal per row (SPEC 8): the number of links off
     * the editor's declared domain, blank when there is none.
     */
    public function formatCell(Model $row, string $key): string
    {
        if ($key === 'domain_alert') {
            $count = (int) $row->getAttribute($key);

            return $count > 0 ? (string) $count : '';
        }

        return parent::formatCell($row, $key);
    }
}
