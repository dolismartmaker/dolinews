<?php

declare(strict_types=1);

namespace App\Core\Admin\Livewire;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Reusable admin form screen: bind a model, validate, persist (S2/S10).
 *
 * A concrete screen extends this and declares fields() and rules(). The
 * component stays thin (S15): validation and a straight save(), no business
 * logic (that belongs to the model/service layer).
 */
abstract class BaseFormComponent extends Component
{
    use AuthorizesAdmin;

    /**
     * The model being edited, or null when creating a new record.
     */
    public ?Model $model = null;

    /**
     * Field values bound to the form inputs, keyed by field name.
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * Gate the screen and hydrate the form from an optional model.
     */
    public function mount(?Model $model = null): void
    {
        $this->mountAuthorizeAdmin();

        $this->model = $model;
        $this->form = $this->initialForm();
    }

    /**
     * Field descriptors driving the generic form view.
     *
     * Each entry: array{key: string, label: string, type: string}.
     * Labels are French with accents (user-facing).
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    abstract protected function fields(): array;

    /**
     * Validation rules keyed by "form.<field>".
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * Seed the form values from the bound model, or with blank defaults.
     *
     * @return array<string, mixed>
     */
    protected function initialForm(): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            $values[$key] = $this->model?->getAttribute($key);
        }

        return $values;
    }

    /**
     * Validate then persist the form (create or update). Emits a flash.
     */
    public function save(): void
    {
        $validated = $this->validate($this->rules());

        /** @var array<string, mixed> $formValues */
        $formValues = $validated['form'] ?? [];

        $model = $this->model ?? $this->newModel();
        $model->fill($formValues);
        $model->save();

        $this->model = $model;

        session()->flash('status', __('Enregistrement effectué.'));
    }

    /**
     * Build a fresh model instance for the create path.
     */
    abstract protected function newModel(): Model;

    /**
     * Heading of the screen, used both as the document title and above the
     * form. A concrete screen overrides it.
     */
    public function heading(): string
    {
        return $this->model === null ? __('Création') : __('Modification');
    }

    /**
     * Render the generic form view inside the admin layout.
     */
    public function render(): View
    {
        return view('core.admin.form', [
            'fields' => $this->fields(),
            'heading' => $this->heading(),
        ])->layout('core.admin.layout')->title($this->heading());
    }
}
