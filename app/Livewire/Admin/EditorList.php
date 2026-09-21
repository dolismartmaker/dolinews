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

    public function heading(): string
    {
        return __('Éditeurs');
    }

    public function intro(): string
    {
        return __('L\'organisation ou la personne qui publie. La validation manuelle est la voie de sortie d\'un éditeur sans dépôt public.');
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
            ['key' => 'contact_email', 'label' => __('Contact'), 'sortable' => false, 'searchable' => true],
            ['key' => 'website', 'label' => __('Site'), 'sortable' => false, 'searchable' => true],
            ['key' => 'verified_at', 'label' => __('Validé le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * The action calls validateEditor() and not validate(): the latter is
     * Livewire's own validation method, which the action dispatcher refuses to
     * call because the component does not declare it -- the button raised a
     * MethodNotFoundException instead of validating anything.
     */
    public function actions(): array
    {
        return [
            ['label' => __('Valider'), 'method' => 'validateEditor'],
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

            $this->dispatch('notify', message: __('Éditeur validé.'));

            return;
        }

        $this->dispatch('notify', message: __('Cet éditeur était déjà validé.'));
    }
}
