<div>
    <h1 style="margin:0 0 0.25rem">{{ $article->title }}</h1>
    <p style="color:#6b7280; font-size:0.9rem; margin:0 0 1rem;">
        {{ $article->editor?->name }} - {{ $article->status->value }}
        @if ($article->submitted_at) - soumis le {{ $article->submitted_at->format('d/m/Y H:i') }} @endif
        - {{ $article->locale }}
        @if ($article->isTranslation()) - traduction (source révision n° {{ $article->source_revision_number }}) @endif
        - accords : {{ count($accords) }} / {{ config('dolinews.review.quorum', 3) }}
    </p>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; align-items:start;">
        <div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
                <h2 style="margin:0 0 0.5rem; font-size:1rem;">Résumé</h2>
                <p>{{ $article->summary }}</p>

                <h2 style="margin:1rem 0 0.5rem; font-size:1rem;">Corps (rendu)</h2>
                <div class="article-body">{!! $bodyHtml !!}</div>
            </div>

            @if ($pendingRevision !== null)
                <div style="background:#fffbeb; border:1px solid #f59e0b; border-radius:6px; padding:1rem; margin-top:1rem;">
                    <h2 style="margin:0 0 0.5rem; font-size:1rem;">Révision en attente - motif : {{ $pendingRevision->motive }}</h2>
                    @foreach ($this->revisionDiff($pendingRevision) as $change)
                        <p style="margin:0.25rem 0;">
                            <strong>{{ $change['field'] }}</strong> :
                            <s>{{ \Illuminate\Support\Str::limit($change['before'], 120) }}</s>
                            -> {{ \Illuminate\Support\Str::limit($change['after'], 120) }}
                        </p>
                    @endforeach
                    {{-- Applying increments the source's revision number, which
                         perimes its translations (SPEC 5.4): stated here so the
                         reviewer decides in full knowledge. --}}
                    <p style="font-size:0.85rem; color:#92400e;">
                        L'application incrémente le numéro de révision de la source et signale ses traductions comme établies d'après une version antérieure.
                    </p>
                    <button wire:click="applyRevision({{ $pendingRevision->getKey() }})" class="row-action-btn" style="background:#047857;">Appliquer</button>
                    <button wire:click="rejectRevision({{ $pendingRevision->getKey() }})" class="row-action-btn" style="background:#b91c1c;">Refuser</button>
                </div>
            @endif
        </div>

        <div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem; margin-bottom:1rem;">
                <h2 style="margin:0 0 0.5rem; font-size:1rem;">Fil de revue</h2>
                @forelse ($thread as $message)
                    <div style="border-bottom:1px solid #f3f4f6; padding:0.5rem 0;">
                        <div style="font-size:0.8rem; color:#6b7280;">
                            {{ $message['author'] }} - {{ $message['created_at'] }}
                            @if ($message['visibility'] === 'moderators')
                                <span style="background:#fef3c7; padding:0 0.4rem; border-radius:4px;">interne</span>
                            @endif
                            @if ($message['decision'])
                                <strong>{{ $message['decision'] }}</strong>
                                @if ($message['rule_ref']) (règle {{ $message['rule_ref'] }}) @endif
                            @endif
                        </div>
                        <div>{{ $message['body'] }}</div>
                    </div>
                @empty
                    <p style="color:#6b7280;">Fil vide : la soumission n'a pas encore de retour.</p>
                @endforelse
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
                <h2 style="margin:0 0 0.5rem; font-size:1rem;">Répondre / décider</h2>
                <form wire:submit="postMessage">
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.85rem; font-weight:600;">Visibilité</label>
                        <select wire:model="messageVisibility" style="width:100%; padding:0.35rem; border:1px solid #d1d5db; border-radius:4px;">
                            <option value="author">Auteur (lu par l'auteur et l'équipe)</option>
                            <option value="moderators">Interne (délibération d'équipe uniquement)</option>
                        </select>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.85rem; font-weight:600;">Décision (facultative)</label>
                        <select wire:model="decision" style="width:100%; padding:0.35rem; border:1px solid #d1d5db; border-radius:4px;">
                            <option value="">aucune</option>
                            <option value="accepted">Accepter (compte pour le quorum)</option>
                            <option value="changes_requested">Demander des modifications</option>
                            <option value="rejected">Refuser</option>
                        </select>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.85rem; font-weight:600;">Règle invoquée (obligatoire pour refuser ou demander des modifications)</label>
                        <input wire:model="ruleRef" type="text" maxlength="20" style="width:100%; padding:0.35rem; border:1px solid #d1d5db; border-radius:4px;" placeholder="R1, R2, ...">
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.85rem; font-weight:600;">Message (texte simple, pas de Markdown)</label>
                        <textarea wire:model="messageBody" style="width:100%; min-height:6rem; padding:0.35rem; border:1px solid #d1d5db; border-radius:4px;"></textarea>
                        @error('messageBody') <span style="color:#b91c1c; font-size:0.82rem;">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit">Publier dans le fil</button>
                </form>
                <p style="font-size:0.8rem; color:#6b7280; margin-top:0.5rem;">
                    Une décision est toujours postée en visibilité auteur : le canal interne délibère, il ne décide jamais en silence.
                    Toute resoumission remet les accords à zéro.
                </p>
            </div>

            @if (auth('web')->user()?->is_super_admin)
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem; margin-top:1rem;">
                    <h2 style="margin:0 0 0.5rem; font-size:1rem;">Dérogation du super administrateur</h2>
                    <p style="font-size:0.85rem; color:#6b7280;">
                        Publier sans quorum : journalisée avec motif obligatoire.
                        Fermée pour vos propres annonces hors phase d'amorçage.
                    </p>
                    <form wire:submit="publishOverride">
                        <input wire:model="overrideMotive" type="text" placeholder="Motif obligatoire" style="width:100%; padding:0.35rem; border:1px solid #d1d5db; border-radius:4px; margin-bottom:0.5rem;">
                        @error('overrideMotive') <span style="color:#b91c1c; font-size:0.82rem;">{{ $message }}</span> @enderror
                        <button type="submit" style="background:#b45309;">Publier sans quorum</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
