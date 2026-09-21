<?php

declare(strict_types=1);

namespace App\Core\Admin\Livewire;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reusable admin list screen: search, sort and pagination (S2/S10).
 *
 * A concrete screen extends this and declares its baseQuery() and columns().
 * The component stays thin (S15): it wires the generic table view and holds
 * no business logic.
 */
abstract class BaseListComponent extends Component
{
    use AuthorizesAdmin;
    use WithPagination;

    /**
     * Free-text search term applied across searchable columns.
     */
    public string $search = '';

    /**
     * Column key currently used for sorting.
     */
    public string $sortField = 'id';

    /**
     * Sort direction: 'asc' or 'desc'.
     */
    public string $sortDir = 'asc';

    /**
     * Rows displayed per page.
     */
    public int $perPage = 15;

    /**
     * Gate the screen on mount (defense in depth on top of route middleware).
     */
    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * The unfiltered Eloquent query the list is built from.
     *
     * @return Builder<covariant Model>
     */
    abstract protected function baseQuery(): Builder;

    /**
     * Column descriptors driving the generic table view.
     *
     * Each entry: array{key: string, label: string, sortable: bool, searchable: bool}.
     * Labels are French with accents (user-facing).
     *
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    abstract protected function columns(): array;

    /**
     * Toggle the sort direction when re-selecting a field, otherwise switch
     * to the new field ascending. Resets pagination.
     */
    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Reset pagination whenever the search term changes (Livewire hook).
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Build the paginated result set: apply search, then sort, then paginate.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    protected function rows(): LengthAwarePaginator
    {
        $query = $this->baseQuery();
        $columns = $this->columns();

        $term = trim($this->search);
        if ($term !== '') {
            $searchable = array_values(array_filter(
                $columns,
                static fn (array $column): bool => $column['searchable'],
            ));

            if ($searchable !== []) {
                $like = '%'.$term.'%';
                $query->where(function (Builder $inner) use ($searchable, $like): void {
                    foreach ($searchable as $column) {
                        $inner->orWhere($column['key'], 'like', $like);
                    }
                });
            }
        }

        $sortField = $this->resolveSortField($columns);
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        /** @var LengthAwarePaginator<int, Model> $paginated */
        $paginated = $query->orderBy($sortField, $sortDir)->paginate($this->perPage);

        return $paginated;
    }

    /**
     * Only allow sorting on a declared sortable column; fall back to id.
     *
     * Guards against a tampered $sortField being pushed into orderBy().
     *
     * @param  list<array{key: string, label: string, sortable: bool, searchable: bool}>  $columns
     */
    private function resolveSortField(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['sortable'] && $column['key'] === $this->sortField) {
                return $this->sortField;
            }
        }

        return 'id';
    }

    /**
     * Row-level instance actions rendered in an extra "Actions" column.
     *
     * Default: no actions. A concrete screen MAY override to expose buttons
     * (impersonate, edit, delete, ...) tied to the row. The generic table
     * view renders the returned list as a final column with one button per
     * action; see resources/views/core/admin/list.blade.php. Backward-
     * compatible: screens that do not override keep the old behaviour and
     * no Actions column is added.
     *
     * Each entry: array{label: string, method: string, class?: string}. The
     * button calls `$component->method($row->getKey())` via wire:click.
     *
     * @return list<array{label: string, method: string, class?: string}>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Format a single cell for display in the generic table view.
     *
     * The default reproduces the previous Blade echo verbatim: it casts the raw
     * attribute exactly as `{{ $row->key }}` did (scalars/Stringable -> string,
     * null -> empty), so every existing socle screen renders byte-for-byte the
     * same. A concrete read-only screen MAY override this to reshape a value
     * (map a status code to a French label, render a boolean symbol, format a
     * derived column) without touching the shared view. Backward-compatible by
     * construction: screens that do not override keep the old behaviour.
     *
     * @param  Model  $row  the current row model
     * @param  string  $key  the column key being rendered
     */
    public function formatCell(Model $row, string $key): string
    {
        $value = $row->getAttribute($key);

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        // Non-scalar, non-stringable (array/object): render as compact JSON so
        // the cell never fatals on echo (the old raw echo would have thrown).
        return (string) json_encode($value);
    }

    /**
     * Render the generic table view inside the admin layout.
     */
    public function render(): View
    {
        return view('core.admin.list', [
            'rows' => $this->rows(),
            'columns' => $this->columns(),
            'actions' => $this->actions(),
        ])->layout('core.admin.layout');
    }
}
