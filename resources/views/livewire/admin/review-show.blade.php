<div>
    <div class="mb-5">
        <a class="link text-sm" href="{{ route('admin.review') }}">{{ __('Retour à la file de revue') }}</a>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $article->title }}</h1>

        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
            <span>{{ $article->editor?->name }}</span>
            <span aria-hidden="true">-</span>
            <span class="badge">{{ $article->status->label() }}</span>
            <span class="badge">{{ $article->locale }}</span>
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
                    <span class="h-2 w-8 rounded-full {{ $i <= count($accords) ? 'bg-accent-600' : 'bg-slate-200 dark:bg-slate-700' }}"></span>
                @endfor
            </div>
            <span class="text-sm font-medium">
                {{ __('Accords :') }} {{ count($accords) }} / {{ $quorum }}
            </span>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
        <div class="space-y-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Résumé') }}</h2>
                    <p class="mt-2 text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Corps de l\'article') }}</h2>
                    {{-- Markdown restricted to a whitelist, rendered
                         server-side: no free HTML ever gets here. --}}
                    <div class="prose-dolinews mt-3">{!! $bodyHtml !!}</div>
                </div>
            </div>

            @if ($pendingRevision !== null)
                <div class="card border-amber-300 dark:border-amber-700">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Révision en attente') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Motif :') }} {{ $pendingRevision->motive }}
                        </p>

                        <dl class="mt-4 space-y-3 text-sm">
                            @foreach ($this->revisionDiff($pendingRevision) as $change)
                                <div>
                                    <dt class="font-medium text-slate-900 dark:text-white">{{ $change['field'] }}</dt>
                                    <dd class="mt-0.5 text-slate-500 line-through dark:text-slate-400">{{ \Illuminate\Support\Str::limit($change['before'], 160) }}</dd>
                                    <dd class="text-slate-800 dark:text-slate-100">{{ \Illuminate\Support\Str::limit($change['after'], 160) }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        {{-- Applying increments the source's revision number,
                             which perimes its translations (SPEC 5.4): stated
                             here so the reviewer decides knowingly. --}}
                        <div class="alert alert-warning mt-4">
                            {{ __('L\'application incrémente le numéro de révision de la source et signale ses traductions comme établies d\'après une version antérieure.') }}
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" wire:click="applyRevision({{ $pendingRevision->getKey() }})">
                                {{ __('Appliquer la révision') }}
                            </button>
                            <button type="button" class="btn btn-danger" wire:click="rejectRevision({{ $pendingRevision->getKey() }})">
                                {{ __('Refuser la révision') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-5">
            <div class="card">
                <div class="card-body">
                    {{-- "Fil de revue" is the private exchange between the
                         author and the team, and not the public feed: there
                         are no public comments on this service. --}}
                    <h2 class="card-title">{{ __('Fil de revue') }}</h2>

                    <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($thread as $message)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $message['author'] }}</span>
                                    <span>{{ $message['created_at'] }}</span>
                                    @if ($message['visibility'] === 'moderators')
                                        <span class="badge badge-warning">{{ __('interne') }}</span>
                                    @endif
                                    @if ($message['decision'])
                                        <span class="badge badge-info">{{ $message['decision'] }}</span>
                                        @if ($message['rule_ref'])
                                            <span>{{ __('règle') }} {{ $message['rule_ref'] }}</span>
                                        @endif
                                    @endif
                                </div>
                                {{-- Plain text, deliberately: the thread is a
                                     workspace, not a publication. --}}
                                <p class="mt-1 text-sm whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $message['body'] }}</p>
                            </div>
                        @empty
                            <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                {{ __('Fil vide : la soumission n\'a pas encore de retour.') }}
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Répondre ou décider') }}</h2>

                    <form wire:submit="postMessage" class="mt-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="msg-visibility">{{ __('Visibilité') }}</label>
                                <select id="msg-visibility" class="input" wire:model="messageVisibility">
                                    <option value="author">{{ __('Auteur (lu par l\'auteur et l\'équipe)') }}</option>
                                    <option value="moderators">{{ __('Interne (délibération d\'équipe)') }}</option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label" for="msg-decision">{{ __('Décision') }}</label>
                                <select id="msg-decision" class="input" wire:model="decision">
                                    <option value="">{{ __('aucune') }}</option>
                                    <option value="accepted">{{ __('Accepter (compte pour le quorum)') }}</option>
                                    <option value="changes_requested">{{ __('Demander des modifications') }}</option>
                                    <option value="rejected">{{ __('Refuser') }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="label" for="msg-rule">{{ __('Règle invoquée') }}</label>
                            <input id="msg-rule" class="input @error('ruleRef') input-error @enderror" type="text" maxlength="20" wire:model="ruleRef" placeholder="R1, R2, ...">
                            <p class="field-hint">{{ __('Obligatoire pour refuser ou demander des modifications.') }}</p>
                            @error('ruleRef') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="msg-body">{{ __('Message') }}</label>
                            <textarea id="msg-body" class="input @error('messageBody') input-error @enderror" rows="5" wire:model="messageBody"></textarea>
                            <p class="field-hint">{{ __('Texte simple : le fil est un espace de travail, pas une publication.') }}</p>
                            @error('messageBody') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Publier dans le fil') }}</button>
                    </form>

                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Une décision est toujours postée en visibilité auteur : le canal interne délibère, il ne décide jamais en silence. Toute resoumission remet les accords à zéro.') }}
                    </p>
                </div>
            </div>

            @if (auth('web')->user()?->is_super_admin)
                <div class="card border-amber-300 dark:border-amber-700">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Dérogation du super administrateur') }}</h2>
                        {{-- Open for a third party's announcement, competitor
                             included; closed for one's own outside the
                             bootstrap phase (SPEC 5.1). --}}
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Publier sans quorum, toujours journalisé avec motif. Fermée pour vos propres annonces hors phase d\'amorçage.') }}
                        </p>

                        <form wire:submit="publishOverride" class="mt-4 space-y-3">
                            <div class="form-control">
                                <label class="label" for="override-motive">{{ __('Motif') }}</label>
                                <input id="override-motive" class="input @error('overrideMotive') input-error @enderror" type="text" wire:model="overrideMotive">
                                @error('overrideMotive') <p class="field-error">{{ $message }}</p> @enderror
                            </div>

                            <button type="submit" class="btn btn-danger">{{ __('Publier sans quorum') }}</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
