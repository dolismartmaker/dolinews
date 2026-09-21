<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Projects\LinkPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Project sheet list (thin). Also surfaces the outgoing-link domain
 * watch (SPEC 8): a link whose domain differs from the editor's
 * declared domain is signalled here for the moderation team.
 */
class ProjectList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Project>
     */
    protected function baseQuery(): Builder
    {
        return Project::query()
            ->select('projects.*')
            ->with(['editor', 'links']);
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
            ['key' => 'domain_alert', 'label' => __('Alerte domaine'), 'sortable' => false, 'searchable' => false],
        ];
    }

    /**
     * Domain-mismatch signal per row (SPEC 8): any link off the
     * editor's declared domain raises the flag.
     */
    public function formatCell(Model $row, string $key): string
    {
        if ($key !== 'domain_alert' || ! $row instanceof Project) {
            return parent::formatCell($row, $key);
        }

        $policy = app(LinkPolicy::class);
        $mismatches = $row->links
            ->filter(static fn ($link): bool => $row->editor !== null
                && ! $policy->matchesEditorDomain($link->url, $row->editor))
            ->count();

        return $mismatches > 0 ? (string) $mismatches : '';
    }
}
