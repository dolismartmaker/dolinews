<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Project sheet list (thin). Also surfaces the outgoing-link domain
 * watch (SPEC 8): a link whose domain differs from the editor's
 * declared domain is signalled here for the moderation team, and the
 * column sorts so the flagged sheets come first.
 *
 * @extends AdminList<Project>
 */
class ProjectList extends AdminList
{
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
        // website raising nothing, Dolistore and forge links never compared.
        $suffix = DB::getDriverName() === 'mysql'
            ? "CONCAT('%.', editors.website_host)"
            : "'%.' || editors.website_host";

        $offDomain = DB::table('project_links')
            ->join('editors', 'editors.id', '=', 'projects.editor_id')
            ->selectRaw('count(*)')
            ->whereColumn('project_links.project_id', 'projects.id')
            ->whereIn('project_links.type', LinkType::editorDomainValues())
            ->whereNotNull('editors.website_host')
            ->where(static function (QueryBuilder $mismatch) use ($suffix): void {
                $mismatch->whereNull('project_links.host')
                    ->orWhere(static function (QueryBuilder $elsewhere) use ($suffix): void {
                        $elsewhere->whereColumn('project_links.host', '!=', 'editors.website_host')
                            ->whereRaw('project_links.host NOT LIKE '.$suffix);
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
        ];
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
