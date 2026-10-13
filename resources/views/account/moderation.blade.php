@extends('layouts.account')

@section('title', __('Actes de modération'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Actes de modération') }}</h1>

    {{-- The journal read by the party it targets (SPEC 9.4): what was done,
         when, under which rule and why. The moderator stays unnamed, the act
         is the team's. --}}
    <p class="mb-6 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
        {{ __('Les actes de l\'équipe de modération qui visent votre compte, vos articles, ou les fiches et annonces des éditeurs dont vous êtes membre. Chacun porte la règle invoquée et son motif : une sanction sans règle numérotée existante au moment des faits n\'est pas applicable.') }}
    </p>

    <div class="space-y-4">
        @forelse ($acts as $act)
            <div class="card">
                <div class="card-body">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge">{{ $act->action->label() }}</span>
                        @if ($act->rule_ref !== null)
                            <span class="badge">{{ __('Règle :rule', ['rule' => $act->rule_ref]) }}</span>
                        @endif
                        @if ($act->is_legal)
                            <span class="badge badge-warning">{{ __('sur injonction légale') }}</span>
                        @endif
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ $act->created_at?->format('d/m/Y H:i') }}</span>
                    </div>

                    <p class="mt-3 font-medium">
                        @if ($act->article !== null)
                            {{ __('Article') }} :
                            @if ($act->article->status->value === 'published' && $act->article->deleted_at === null)
                                <a class="link" href="{{ route('articles.show', $act->article) }}">{{ $act->article->title }}</a>
                            @else
                                {{ $act->article->title }}
                            @endif
                        @elseif ($act->project !== null)
                            {{ __('Fiche projet') }} :
                            @if ($act->project->isHidden())
                                {{ $act->project->name }}
                            @else
                                <a class="link" href="{{ route('projects.show', $act->project->slug) }}">{{ $act->project->name }}</a>
                            @endif
                            @if ($act->action->value === 'transferred' && $act->editor !== null)
                                - {{ __('transférée à :editor', ['editor' => $act->editor->name]) }}
                            @endif
                        @elseif ($act->user_id === auth()->id())
                            {{ __('Votre compte') }}
                        @elseif ($act->editor !== null)
                            {{ __('Éditeur') }} : {{ $act->editor->name }}
                        @endif
                    </p>

                    <p class="mt-2 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $act->motive }}</p>

                    {{-- A withdrawal taken in conflict of interest lives seven
                         days without confirmation, then is lifted (SPEC 9.6);
                         the author is owed that it may not last. --}}
                    @if ($act->moderator_user_id === null)
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Écrit par le service : un acte non confirmé dans le délai a été annulé d\'office.') }}</p>
                    @elseif ($act->requires_confirmation)
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ $act->confirmed_at !== null
                                ? __('Pris en situation de conflit d\'intérêts, confirmé par un second modérateur.')
                                : __('Pris en situation de conflit d\'intérêts : sans confirmation par un second modérateur sous sept jours, il sera annulé d\'office.') }}
                        </p>
                    @endif
                </div>
            </div>
        @empty
            <div class="card">
                <div class="card-body text-slate-500 dark:text-slate-400">
                    {{ __('Aucun acte de modération ne vous concerne.') }}
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $acts->links() }}
    </div>
@endsection
