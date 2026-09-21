<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * Media list (thin, read-only): what was uploaded, what is bound, what
 * the nightly purge will collect.
 */
class MediaList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Media>
     */
    protected function baseQuery(): Builder
    {
        return Media::query()->select('media.*');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'sortable' => true, 'searchable' => false],
            ['key' => 'path', 'label' => 'Fichier', 'sortable' => false, 'searchable' => true],
            ['key' => 'mime', 'label' => 'Type', 'sortable' => true, 'searchable' => false],
            ['key' => 'bytes', 'label' => 'Taille', 'sortable' => true, 'searchable' => false],
            ['key' => 'article_id', 'label' => 'Article', 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => 'Déposé le', 'sortable' => true, 'searchable' => false],
        ];
    }
}
