@extends('layouts.public')

@section('title', __('Mentions légales'))

@section('content')
    <div class="card mx-auto max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Mentions légales') }}</h1>

            <div class="prose-dolinews mt-4">
                <p>{{ __('DoliNews est un fil d\'annonces pour l\'écosystème Dolibarr, alimenté par les éditeurs de modules et de services.') }}</p>

                {{-- LCEN art. 6 III: who publishes, who answers for it, and
                     how to reach them. Every value comes from the
                     deployment's configuration. --}}
                <h2>{{ __('Éditeur du service') }}</h2>
                <dl>
                    @foreach ([
                        'name' => __('Raison sociale'),
                        'form' => __('Forme juridique'),
                        'capital' => __('Capital social'),
                        'address' => __('Siège social'),
                        'siret' => __('SIRET'),
                        'registry' => __('Immatriculation'),
                        'vat' => __('TVA intracommunautaire'),
                        'phone' => __('Téléphone'),
                    ] as $field => $label)
                        @if (($publisher[$field] ?? '') !== '')
                            <dt>{{ $label }}</dt>
                            <dd>{{ $publisher[$field] }}</dd>
                        @endif
                    @endforeach
                    @if ($director !== '')
                        <dt>{{ __('Directeur de la publication') }}</dt>
                        <dd>{{ $director }}</dd>
                    @endif
                </dl>

                {{-- The hosting regime is deliberately given up: with an
                     a-priori review, the operator is the editor of the
                     validated contents (SPEC 9.7). --}}
                <h2>{{ __('Responsabilité éditoriale') }}</h2>
                <p>{{ __('La revue a priori fait de l\'exploitant l\'éditeur des contenus validés, responsable de ceux-ci au sens de la LCEN. C\'est un choix délibéré, prix du contrôle éditorial.') }}</p>

                @if (($host['name'] ?? '') !== '')
                    <h2>{{ __('Hébergement') }}</h2>
                    <dl>
                        <dt>{{ __('Hébergeur') }}</dt>
                        <dd>{{ $host['name'] }}</dd>
                        @if (($host['address'] ?? '') !== '')
                            <dt>{{ __('Adresse') }}</dt>
                            <dd>{{ $host['address'] }}</dd>
                        @endif
                        @if (($host['phone'] ?? '') !== '')
                            <dt>{{ __('Téléphone') }}</dt>
                            <dd>{{ $host['phone'] }}</dd>
                        @endif
                    </dl>
                    @if (($host['server_location'] ?? '') !== '')
                        <p>{{ __('Le serveur est situé :location.', ['location' => __($host['server_location'])]) }}
                            @if (($host['backup_location'] ?? '') !== '')
                                {{ __('Les sauvegardes sont conservées :location.', ['location' => __($host['backup_location'])]) }}
                            @endif
                        </p>
                    @endif
                @endif

                {{-- DSA art. 11 and 12: one point of contact for the
                     authorities and the users alike, with the languages it
                     reads. The form comes first, the address is there for
                     whoever needs one. --}}
                @if ($contactUrl !== '' || $contactEmail !== '')
                    <h2>{{ __('Point de contact') }}</h2>
                    <p>{{ __('Point de contact unique des autorités des États membres, de la Commission européenne, du comité européen des services numériques et des utilisateurs du service, au sens des articles 11 et 12 du règlement (UE) 2022/2065 sur les services numériques.') }}</p>
                    <dl>
                        @if ($contactUrl !== '')
                            <dt>{{ __('Formulaire de contact') }}</dt>
                            <dd><a class="break-all" href="{{ $contactUrl }}" rel="nofollow">{{ $contactUrl }}</a></dd>
                        @endif
                        @if ($contactEmail !== '')
                            <dt>{{ __('Courriel') }}</dt>
                            <dd><a class="break-all" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></dd>
                        @endif
                        @if ($contactLanguages !== [])
                            <dt>{{ __('Langues acceptées') }}</dt>
                            <dd>{{ implode(', ', $contactLanguages) }}</dd>
                        @endif
                    </dl>
                    <p>{{ __('Pour signaler une annonce ou une fiche précise, utilisez le lien de signalement qu\'elle porte : il va directement à l\'équipe de modération.') }}</p>
                @endif

                {{-- GDPR art. 13: controller, DPO, recipients, cookies and
                     the supervisory authority. The shared translation
                     engine is located, never named (SPEC 5.7). --}}
                <h2>{{ __('Données personnelles') }}</h2>
                @if (($publisher['name'] ?? '') !== '' || $dpoEmail !== '')
                    <dl>
                        @if (($publisher['name'] ?? '') !== '')
                            <dt>{{ __('Responsable du traitement') }}</dt>
                            <dd>{{ $publisher['name'] }}</dd>
                        @endif
                        @if ($dpoEmail !== '')
                            <dt>{{ __('Délégué à la protection des données') }}</dt>
                            <dd><a class="break-all" href="mailto:{{ $dpoEmail }}">{{ $dpoEmail }}</a></dd>
                        @endif
                    </dl>
                @endif
                @if ($translationLocation !== '')
                    <p>{{ __('Le texte des annonces et des fiches dont l\'éditeur a activé la traduction automatique est transmis à un service de traduction hébergé :location, qui ne reçoit que ces textes.', ['location' => __($translationLocation)]) }}</p>
                @endif
                <p>{{ __('Un éditeur qui a déposé sa propre clé de traduction fait traduire ses textes par le fournisseur de cette clé, selon le contrat qui les lie.') }}</p>
                <p>{{ __('Le service ne dépose que les cookies nécessaires à son fonctionnement : la session et la protection contre la falsification de requêtes. Il ne dépose aucun cookie de mesure d\'audience ni de publicité, et ne demande donc aucun consentement.') }}</p>
                <p>{{ __('Vous disposez d\'un droit d\'accès, de rectification, d\'effacement, de limitation, d\'opposition et de portabilité, et pouvez introduire une réclamation auprès de la CNIL.') }}
                    <a href="https://www.cnil.fr" rel="nofollow">www.cnil.fr</a></p>
                <p>{{ __('Le détail des données traitées :') }} <a href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></p>

                {{-- A paid volume sold to professionals only spares the
                     operator the consumer mediator (Code de la
                     consommation, art. L612-1). --}}
                @if ($professionalsOnly && ($publisher['name'] ?? '') !== '')
                    <h2>{{ __('Offre payante') }}</h2>
                    <p>{{ __('Un volume de traduction automatique au-delà du plafond commun peut être vendu aux éditeurs. Cette offre est réservée aux professionnels et facturée hors du service par :name.', ['name' => $publisher['name']]) }}</p>
                @endif

                <h2>{{ __('Licences') }}</h2>
                {{-- The licence is nothing without the address: the AGPL owes
                     the source to the users of the service (SPEC D13). --}}
                <p>{{ __('Le code du service est publié sous licence GNU AGPL v3.') }}
                    <a class="link break-all" href="{{ $sourceUrl }}" rel="nofollow">{{ $sourceUrl }}</a></p>
                <p>{{ __('Les contenus publiés - articles, fiches projet, traductions - sont diffusés sous licence Creative Commons Attribution - Partage dans les mêmes conditions 4.0 (CC BY-SA 4.0). L\'auteur conserve ses droits d\'auteur et sa paternité ; en soumettant, il place son contenu sous cette licence, ce qui autorise le service et les tiers à le reproduire, le diffuser et l\'adapter, à condition de citer l\'auteur et de conserver la même licence.') }}</p>
                <p>{{ __('En soumettant, l\'auteur déclare détenir les droits sur son contenu, donc le pouvoir de le placer sous cette licence, et s\'engage à n\'enfreindre aucune loi ni aucun droit de tiers, notamment le droit des marques, des noms commerciaux et des logos.') }}</p>

                <h2>{{ __('Marques') }}</h2>
                <p>{{ __('Dolibarr est une marque de la Fondation Dolibarr. DoliNews est un service indépendant, ni affilié à la Fondation Dolibarr ni approuvé par elle. Les noms de modules, de produits et de sociétés cités sur ce site appartiennent à leurs titulaires respectifs.') }}</p>

                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Dernière mise à jour : :date', ['date' => $updatedAt->locale(app()->getLocale())->isoFormat('LL')]) }}</p>
            </div>
        </div>
    </div>
@endsection
