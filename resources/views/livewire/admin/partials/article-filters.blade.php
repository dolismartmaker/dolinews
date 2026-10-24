<div class="mb-4 flex flex-wrap gap-x-6 gap-y-2">
    <x-mary-checkbox :label="__('Sans fiche uniquement (:count)', ['count' => $this->withoutSheetCount()])" wire:model.live="withoutSheet" />
    <x-mary-checkbox :label="__('Afficher les annonces refusées')" wire:model.live="showRejected" />
</div>
