@extends('layouts.public')

@section('title', __('Mentions légales'))

@section('content')
    <div class="card mx-auto max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Mentions légales') }}</h1>

            <div class="prose-dolinews mt-4">
                <p>{{ __('DoliNews est un fil d\'annonces pour l\'écosystème Dolibarr, alimenté par les éditeurs de modules et de services.') }}</p>
                {{-- The hosting regime is deliberately given up: with an
                     a-priori review, the operator is the editor of the
                     validated contents (SPEC 9.7). --}}
                <p>{{ __('La revue a priori fait de l\'exploitant l\'éditeur des contenus validés, responsable de ceux-ci au sens de la LCEN. C\'est un choix délibéré, prix du contrôle éditorial.') }}</p>
                {{-- The licence is nothing without the address: the AGPL owes
                     the source to the users of the service (SPEC D13). --}}
                <p>{{ __('Le code du service est publié sous licence GNU AGPL v3.') }}
                    <a class="link break-all" href="{{ config('dolinews.source_url') }}" rel="nofollow">{{ config('dolinews.source_url') }}</a></p>
                <p>{{ __('Les contenus publiés - articles, fiches projet, traductions - sont diffusés sous licence Creative Commons Attribution - Partage dans les mêmes conditions 4.0 (CC BY-SA 4.0). L\'auteur conserve ses droits d\'auteur et sa paternité ; en soumettant, il place son contenu sous cette licence, ce qui autorise le service et les tiers à le reproduire, le diffuser et l\'adapter, à condition de citer l\'auteur et de conserver la même licence.') }}</p>
                <p>{{ __('En soumettant, l\'auteur déclare détenir les droits sur son contenu, donc le pouvoir de le placer sous cette licence, et s\'engage à n\'enfreindre aucune loi ni aucun droit de tiers, notamment le droit des marques, des noms commerciaux et des logos.') }}</p>
            </div>
        </div>
    </div>
@endsection
