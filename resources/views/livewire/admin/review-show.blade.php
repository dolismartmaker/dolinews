<div>
    @if ($notice !== null)
        <x-mary-alert :title="$notice" icon="o-check-circle" class="alert-success mb-5" />
    @endif

    <div class="mb-5">
        <a class="link text-sm" href="{{ route('admin.review.index') }}">{{ __('Retour à la file de revue') }}</a>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $article->title }}</h1>

        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-base-content/70">
            <span>{{ $article->editor?->name }}</span>
            <span aria-hidden="true">-</span>
            <x-mary-badge :value="$article->status->label()" class="badge-sm" />
            <x-mary-badge :value="$article->locale" class="badge-sm" />
            @if ($article->submitted_at)
                <span aria-hidden="true">-</span>
                <span>{{ __('soumis le') }} {{ $article->submitted_at->format('d/m/Y H:i') }}</span>
            @endif
            @if ($article->isTranslation())
                <span aria-hidden="true">-</span>
                {{-- A translation is an article in its own right and goes
                     through the review too (SPEC 5.4). --}}
                <span>{{ __('traduction, source en révision n°') }} {{ $article->source_revision_number }}</span>
            @endif
        </p>

        @php($quorum = (int) config('dolinews.review.quorum', 3))
        <div class="mt-3 flex items-center gap-3">
            <div class="flex gap-1" aria-hidden="true">
                @for ($i = 1; $i <= $quorum; $i++)
                    <span class="h-2 w-8 rounded-full {{ $i <= count($accords) ? 'bg-primary' : 'bg-base-300' }}"></span>
                @endfor
            </div>
            <span class="text-sm font-medium">
                {{ __('Accords :') }} {{ count($accords) }} / {{ $quorum }}
            </span>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
        <div class="space-y-5">
            <x-mary-card :title="__('Résumé')">
                <p>{{ $article->summary }}</p>
            </x-mary-card>

            <x-mary-card :title="__('Corps de l\'article')">
                {{-- Markdown restricted to a whitelist, rendered server-side:
                     no free HTML ever gets here. --}}
                <div class="admin-prose">{!! $bodyHtml !!}</div>
            </x-mary-card>

            {{-- The album of what the submission carries. A forbidden picture
                 is not something a reviewer should have to find by scrolling
                 the rendered body, and an image the body never shows would
                 not appear there at all (rules R3/R6). --}}
            <x-mary-card>
                <x-slot:title>
                    {{ __('Images jointes') }}
                    <x-mary-badge :value="(string) $media->count()" class="badge-sm ml-1" />
                </x-slot:title>

                @if ($media->isEmpty())
                    <p class="text-sm text-base-content/70">{{ __('Aucune image jointe à cette soumission.') }}</p>
                @else
                    <p class="text-sm text-base-content/70">
                        {{ __('Survolez une vignette pour la voir en grand, cliquez pour ouvrir le fichier.') }}
                    </p>

                    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($media as $file)
                            <div wire:key="media-{{ $file->getKey() }}">
                                <x-mary-popover>
                                    <x-slot:trigger>
                                        <a href="{{ $file->url() }}" target="_blank" rel="noopener noreferrer nofollow" class="block overflow-hidden rounded-field border border-base-300">
                                            {{-- Sandboxed by construction: re-encoded at
                                                 intake, SVG refused (D7). --}}
                                            <img src="{{ $file->url() }}" alt="{{ $file->alt ?? '' }}" loading="lazy" class="h-28 w-full object-cover">
                                        </a>
                                    </x-slot:trigger>
                                    <x-slot:content>
                                        <img src="{{ $file->url() }}" alt="" class="max-h-96 max-w-sm rounded-field object-contain">
                                        <p class="mt-2 text-xs text-base-content/70">{{ $file->width }}x{{ $file->height }} - {{ $file->mime }}</p>
                                    </x-slot:content>
                                </x-mary-popover>
                                <p class="mt-1 truncate text-xs text-base-content/70" title="{{ $file->alt }}">
                                    @if ($file->alt)
                                        {{ $file->alt }}
                                    @else
                                        {{-- An image with no alternative text is also
                                             a review remark to make. --}}
                                        <span class="italic">{{ __('sans texte de remplacement') }}</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-mary-card>

            @if ($pendingRevision !== null)
                <x-mary-card :title="__('Révision en attente')" :subtitle="__('Motif :').' '.$pendingRevision->motive" class="border border-warning">
                    <dl class="space-y-3 text-sm">
                        @foreach ($this->revisionDiff($pendingRevision) as $change)
                            <div>
                                <dt class="font-medium">{{ $change['field'] }}</dt>
                                <dd class="mt-0.5 text-base-content/60 line-through">{{ \Illuminate\Support\Str::limit($change['before'], 160) }}</dd>
                                <dd>{{ \Illuminate\Support\Str::limit($change['after'], 160) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    {{-- Applying increments the source's revision number, which
                         perimes its translations (SPEC 5.4): stated here so
                         the reviewer decides knowingly. --}}
                    <x-mary-alert :title="__('L\'application incrémente le numéro de révision de la source et signale ses traductions comme établies d\'après une version antérieure.')"
                                  icon="o-exclamation-triangle" class="alert-warning mt-4" />

                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-mary-button :label="__('Appliquer la révision')" wire:click="applyRevision({{ $pendingRevision->getKey() }})" class="btn-primary" spinner />
                        <x-mary-button :label="__('Refuser la révision')" wire:click="rejectRevision({{ $pendingRevision->getKey() }})" class="btn-error" spinner />
                    </div>
                </x-mary-card>
            @endif
        </div>

        <div class="space-y-5">
            {{-- "Fil de revue" is the private exchange between the author and
                 the team, and not the public feed: there are no public
                 comments on this service. --}}
            <x-mary-card :title="__('Fil de revue')">
                <div class="divide-y divide-base-300">
                    @forelse ($thread as $message)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-base-content/70">
                                <span class="font-medium text-base-content">{{ $message['author'] }}</span>
                                <span>{{ $message['created_at'] }}</span>
                                @if ($message['visibility'] === 'moderators')
                                    <x-mary-badge :value="__('interne')" class="badge-warning badge-sm" />
                                @endif
                                @if ($message['decision'])
                                    <x-mary-badge :value="$message['decision']" class="badge-info badge-sm" />
                                    @if ($message['rule_ref'])
                                        <span>{{ __('règle') }} {{ $message['rule_ref'] }}</span>
                                    @endif
                                @endif
                            </div>
                            {{-- Plain text, deliberately: the thread is a
                                 workspace, not a publication. --}}
                            <p class="mt-1 text-sm whitespace-pre-line">{{ $message['body'] }}</p>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-base-content/70">
                            {{ __('Fil vide : la soumission n\'a pas encore de retour.') }}
                        </p>
                    @endforelse
                </div>
            </x-mary-card>

            <x-mary-card :title="__('Répondre ou décider')">
                <form wire:submit="postMessage" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-mary-select :label="__('Visibilité')" wire:model="messageVisibility" :options="[
                            ['id' => 'author', 'name' => __('Auteur (lu par l\'auteur et l\'équipe)')],
                            ['id' => 'moderators', 'name' => __('Interne (délibération d\'équipe)')],
                        ]" />
                        <x-mary-select :label="__('Décision')" wire:model="decision" :placeholder="__('aucune')" placeholder-value="" :options="[
                            ['id' => 'accepted', 'name' => __('Accepter (compte pour le quorum)')],
                            ['id' => 'changes_requested', 'name' => __('Demander des modifications')],
                            ['id' => 'rejected', 'name' => __('Refuser')],
                        ]" />
                    </div>

                    <x-mary-input :label="__('Règle invoquée')" wire:model="ruleRef" maxlength="20" placeholder="R1, R2, ..."
                                  :hint="__('Obligatoire pour refuser ou demander des modifications.')" />
                    <x-mary-textarea :label="__('Message')" wire:model="messageBody" rows="5"
                                     :hint="__('Texte simple : le fil est un espace de travail, pas une publication.')" />

                    <x-mary-button type="submit" :label="__('Publier dans le fil')" class="btn-primary" spinner="postMessage" />
                </form>

                <p class="mt-4 text-sm text-base-content/70">
                    {{ __('Une décision est toujours postée en visibilité auteur : le canal interne délibère, il ne décide jamais en silence. Toute resoumission remet les accords à zéro.') }}
                </p>
            </x-mary-card>

            @if (auth('web')->user()?->is_super_admin)
                {{-- Open for a third party's announcement, competitor included;
                     closed for one's own outside the bootstrap phase
                     (SPEC 5.1). --}}
                <x-mary-card :title="__('Dérogation du super administrateur')"
                             :subtitle="__('Publier sans quorum, toujours journalisé avec motif. Fermée pour vos propres annonces hors phase d\'amorçage.')"
                             class="border border-warning">
                    <form wire:submit="publishOverride" class="space-y-3">
                        <x-mary-input :label="__('Motif')" wire:model="overrideMotive" />
                        <x-mary-button type="submit" :label="__('Publier sans quorum')" class="btn-error" spinner="publishOverride" />
                    </form>
                </x-mary-card>
            @endif
        </div>
    </div>
</div>
