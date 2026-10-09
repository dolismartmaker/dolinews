<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesAdmin;
use Caprel\Admin\Livewire\Concerns\HasBulkSelection;
use Caprel\Admin\Livewire\Concerns\WithSortableTable;
use Caprel\Admin\Support\CsvDownload;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list screen of the back-office, on the traits of caprel/laravel-admin.
 *
 * A screen declares its query and its columns; search, sort, pagination,
 * selection and the CSV export of the selection come from here. Nothing in
 * DoliNews is deleted from a list - the moderation acts withdraw, mask or
 * suspend one row at a time, each with its rule and its motive - so the
 * batch action every list carries is the export.
 *
 * @template TModel of Model
 */
#[Layout('admin::layouts.admin')]
abstract class AdminList extends Component
{
    use AuthorizesAdmin;
    use HasBulkSelection;
    use WithSortableTable;

    /**
     * Rows displayed per page.
     */
    protected int $perPage = 15;

    /**
     * Gate the screen on mount, on top of the route middleware.
     */
    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * The unfiltered query the list is built from, already bound to what
     * the viewer may see.
     *
     * @return Builder<TModel>
     */
    abstract protected function baseQuery(): Builder;

    /**
     * Each entry: array{key: string, label: string, sortable: bool, searchable: bool}.
     *
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    abstract protected function columns(): array;

    /**
     * Row actions, one button per entry calling `method(rowKey)`.
     *
     * @return list<array{label: string, method: string, class?: string}>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * One cell as it is read: scalars as they are, an enum by its label, a
     * date short. A screen overrides it to spell out a boolean or a relation.
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

        if ($value instanceof \BackedEnum) {
            if (method_exists($value, 'label')) {
                return (string) $value->label();
            }

            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y H:i');
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    /**
     * Heading of the screen, also the document title.
     */
    public function heading(): string
    {
        return __('Administration');
    }

    /**
     * Optional explanation under the heading, for a screen whose reading
     * needs a caveat.
     */
    public function intro(): string
    {
        return '';
    }

    /**
     * Blade view of the act panel shown above the table, or null. The panel
     * decides on its own whether an act is open.
     */
    public function panelView(): ?string
    {
        return null;
    }

    public function updatingSearch(): void
    {
        $this->resetSelection();
    }

    public function exportSelected(): ?StreamedResponse
    {
        $this->requireBulkRead();

        $rows = $this->selectedFrom($this->listQuery());

        if ($rows === []) {
            $this->reportBatch(0, 0);

            return null;
        }

        $columns = $this->columns();

        Log::info('AdminList: selection exported', [
            'user_id' => auth()->id(),
            'component' => static::class,
            'rows' => count($rows),
        ]);
        $this->resetSelection();

        return CsvDownload::stream(
            Str::slug($this->heading()).'-'.now()->format('Ymd-His').'.csv',
            array_map(static fn (array $column): string => $column['label'], $columns),
            array_map(fn (Model $row): array => array_map(
                fn (array $column): string => $this->formatCell($row, $column['key']),
                $columns,
            ), $rows),
        );
    }

    public function render(): View
    {
        return view('livewire.admin.list', [
            'rows' => $this->tableRows(),
            'headers' => $this->tableHeaders(),
            'actions' => $this->actions(),
            'heading' => $this->heading(),
            'intro' => $this->intro(),
            'panelView' => $this->panelView(),
        ])->title($this->heading());
    }

    /**
     * The one query of the screen: display, page keys and export.
     *
     * @return Builder<TModel>
     */
    protected function listQuery(): Builder
    {
        $searchable = array_values(array_map(
            static fn (array $column): string => $column['key'],
            array_filter($this->columns(), static fn (array $column): bool => $column['searchable']),
        ));

        return $this->applySort($this->applySearch($this->baseQuery(), $searchable));
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function rows(): LengthAwarePaginator
    {
        return $this->listQuery()->paginate($this->perPage);
    }

    /**
     * The page as the table reads it: the key, then every column already
     * formatted, so the generic view needs no cell of its own.
     *
     * @return LengthAwarePaginator<int, non-empty-array<string, int|string>>
     */
    protected function tableRows(): LengthAwarePaginator
    {
        $columns = $this->columns();
        $page = $this->rows();

        return $page->through(function (Model $row) use ($columns): array {
            $cells = ['id' => (int) $row->getKey()];

            foreach ($columns as $column) {
                $cells[$column['key']] = $this->formatCell($row, $column['key']);
            }

            return $cells;
        });
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool}>
     */
    protected function tableHeaders(): array
    {
        return array_map(static fn (array $column): array => [
            'key' => $column['key'],
            'label' => $column['label'],
            'sortable' => $column['sortable'],
        ], $this->columns());
    }

    /**
     * @return array{column: string, direction: string}
     */
    protected function defaultSort(): array
    {
        return ['column' => 'id', 'direction' => 'desc'];
    }

    /**
     * @return list<string>
     */
    protected function sortableColumns(): array
    {
        return array_values(array_map(
            static fn (array $column): string => $column['key'],
            array_filter($this->columns(), static fn (array $column): bool => $column['sortable']),
        ));
    }

    protected function requireBulkRead(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return array<int, string>
     */
    protected function bulkPageIds(): array
    {
        return $this->rows()
            ->getCollection()
            ->map(static fn (Model $row): string => (string) $row->getKey())
            ->all();
    }
}
