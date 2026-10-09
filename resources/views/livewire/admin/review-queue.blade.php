<div>
    {{-- No target delay is ever announced: committing volunteers' spare
         time would turn every hold-up into a breach. What is shown is the
         observed delay, which measures without promising (SPEC 9.5). --}}
    <x-mary-header :title="$heading" :subtitle="__('Priorité aux annonces de sécurité, puis ancienneté. Ces chiffres sont publics et mesurent : ils ne promettent rien.')" separator progress-indicator />

    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:max-w-lg">
        <x-mary-stat :title="__('Délai observé (médiane)')" :value="$medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-'" icon="o-clock" />
        <x-mary-stat :title="__('Attente la plus ancienne')" :value="$oldestPendingDays !== null ? $oldestPendingDays.' '.__('j') : '-'" icon="o-calendar-days" />
    </div>

    @if ($hasDeclaredLocales)
        {{-- A display filter, never a right: the queue stays open to the
             whole team, and unchecking shows it entirely (SPEC 5.1). --}}
        <x-mary-checkbox :label="__('N\'afficher que les langues que je relis')" wire:model.live="onlyMyLanguages" class="mb-4" />
    @endif

    <x-admin::bulk-bar :count="count($selected)">
        <x-mary-button :label="__('admin::bulk.export')" wire:click="exportSelected" icon="o-arrow-down-tray" class="btn-sm" spinner />
    </x-admin::bulk-bar>

    {{-- An empty list says why it is empty: a filtered queue that looks
         empty would read as a queue nobody is waiting in. --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$rows" with-pagination
                      selectable wire:model.live="selected"
                      show-empty-text :empty-text="$hasDeclaredLocales && $onlyMyLanguages
                          ? __('Aucune soumission en attente dans les langues que vous relisez. Décochez le filtre pour voir la file entière.')
                          : __('File vide : aucune soumission en attente.')">
            @scope('cell_title', $article)
                <span class="font-medium">{{ $article->title }}</span>
            @endscope
            @scope('cell_focus', $article)
                {{ $this->formatCell($article, 'focus') }}
            @endscope
            @scope('cell_submitted_at', $article)
                {{ $this->formatCell($article, 'submitted_at') }}
            @endscope
            @scope('cell_author', $article)
                {{ $article->author?->display_name ?? $article->author?->name ?? '-' }}
            @endscope
            @scope('actions', $article)
                <x-mary-button :label="__('Relire')" :link="route('admin.review.show', $article)" class="btn-sm btn-primary" />
            @endscope
        </x-mary-table>
    </x-mary-card>
</div>
