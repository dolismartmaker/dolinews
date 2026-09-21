<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\Editor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Editor list (thin): directory plus the manual validation of editors
 * without a public repository (SPEC 3.3), which sets verified_at.
 */
class EditorList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Editor>
     */
    protected function baseQuery(): Builder
    {
        return Editor::query()->select('editors.*');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'sortable' => true, 'searchable' => false],
            ['key' => 'slug', 'label' => 'Slug', 'sortable' => true, 'searchable' => true],
            ['key' => 'name', 'label' => 'Nom', 'sortable' => true, 'searchable' => true],
            ['key' => 'contact_email', 'label' => 'Contact', 'sortable' => false, 'searchable' => true],
            ['key' => 'website', 'label' => 'Site', 'sortable' => false, 'searchable' => true],
            ['key' => 'verified_at', 'label' => 'Validé le', 'sortable' => true, 'searchable' => false],
        ];
    }

    public function actions(): array
    {
        return [
            ['label' => 'Valider', 'method' => 'validate'],
        ];
    }

    /**
     * Manual validation of an editor (SPEC 3.3): sets verified_at.
     */
    public function validateEditor(int $editorId): void
    {
        $editor = Editor::query()->findOrFail($editorId);

        if ($editor->verified_at === null) {
            $editor->verified_at = now();
            $editor->save();
        }
    }
}
