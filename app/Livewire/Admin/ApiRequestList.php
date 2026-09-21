<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use App\Core\Admin\Livewire\BaseListComponent;
use App\Models\ApiRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * API-call observability screen (S10): lists logged API requests.
 *
 * Thin by design (S15): it only declares the query and columns; searching,
 * sorting and pagination are handled by BaseListComponent, and no business
 * logic lives here.
 */
class ApiRequestList extends BaseListComponent
{
    use AuthorizesAdmin;

    /**
     * Column key currently used for sorting.
     *
     * Newest calls first is the useful default for an observability list.
     */
    public string $sortField = 'id';

    /**
     * Sort direction: 'asc' or 'desc'. Defaults to descending on id.
     */
    public string $sortDir = 'desc';

    /**
     * Re-check the admin capability on mount (defense in depth on top of the
     * 'admin' route middleware).
     */
    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * The unfiltered query the list is built from.
     *
     * @return Builder<ApiRequest>
     */
    protected function baseQuery(): Builder
    {
        return ApiRequest::query();
    }

    /**
     * Column descriptors driving the generic table view.
     *
     * Labels are French with accents (user-facing).
     *
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => __('Date'), 'sortable' => true, 'searchable' => false],
            ['key' => 'user_id', 'label' => __('Compte'), 'sortable' => true, 'searchable' => false],
            ['key' => 'method', 'label' => __('Méthode'), 'sortable' => true, 'searchable' => false],
            ['key' => 'path', 'label' => __('Chemin'), 'sortable' => true, 'searchable' => true],
            ['key' => 'status', 'label' => __('Statut'), 'sortable' => true, 'searchable' => false],
        ];
    }

    public function heading(): string
    {
        return __('Appels API');
    }

    public function intro(): string
    {
        return __('Observabilité des appels : un jeton donne le droit de soumettre, jamais celui de publier.');
    }
}
