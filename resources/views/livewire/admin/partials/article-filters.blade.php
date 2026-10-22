<x-mary-checkbox :label="__('Sans fiche uniquement (:count)', ['count' => $this->withoutSheetCount()])"
                 wire:model.live="withoutSheet" class="mb-4" />
