<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public function heading(): string
    {
        return __('Médias');
    }

    public function intro(): string
    {
        return __('Toute image est ré-encodée à l\'entrée et dépouillée de ses métadonnées : le type déclaré et l\'extension ne sont jamais des preuves.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'path', 'label' => __('Fichier'), 'sortable' => false, 'searchable' => true],
            ['key' => 'alt', 'label' => __('Texte de remplacement'), 'sortable' => false, 'searchable' => true],
            ['key' => 'mime', 'label' => __('Type'), 'sortable' => true, 'searchable' => false],
            ['key' => 'bytes', 'label' => __('Taille'), 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => __('Déposé le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * Its own view rather than the generic table: moderating an image means
     * seeing it, and a path with a MIME type says nothing about what the file
     * actually shows. The view adds a thumbnail column that enlarges on hover.
     */
    public function render(): View
    {
        return view('livewire.admin.media-list', [
            'rows' => $this->rows(),
            'columns' => $this->columns(),
            'heading' => $this->heading(),
            'intro' => $this->intro(),
        ])->layout('core.admin.layout')->title($this->heading());
    }

    /**
     * Sizes in bytes make a column of figures nobody compares: a stored image
     * is read in kilobytes.
     */
    public function formatCell(Model $row, string $key): string
    {
        if ($key === 'bytes') {
            $bytes = (int) $row->getAttribute($key);

            return $bytes < 1024
                ? $bytes.' o'
                : number_format($bytes / 1024, 0, ',', ' ').' ko';
        }

        return parent::formatCell($row, $key);
    }
}
