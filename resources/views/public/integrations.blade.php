@extends('layouts.public')

@section('title', __('Afficher le fil sur votre site'))

@section('content')
    {{-- How a third-party site shows a section of the feed (SPEC 6.4).
         Every address comes from the controller, so a self-hosted
         deployment documents its own endpoints rather than ours.

         Written for whoever runs a site, not for whoever publishes
         through a token: that reader has the API documentation. --}}
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="card">
            <div class="card-body sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Afficher le fil sur votre site') }}</h1>

                <div class="prose-dolinews mt-4">
                    <p>{{ __('La lecture du fil est gratuite et sans compte, flux compris : afficher les annonces d\'un éditeur ou d\'un projet sur votre propre site ne demande ni inscription, ni jeton, ni autorisation.') }}</p>

                    <p>{{ __('Les annonces sont publiées sous licence CC BY-SA 4.0. Une reprise cite donc le nom de l\'éditeur, renvoie vers l\'annonce et nomme la licence : l\'extension et les flux ci-dessous portent ces trois éléments, les retirer rend la copie non autorisée.') }}</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('WordPress, en un fichier') }}</h2>

                <div class="prose-dolinews mt-3">
                    <p>{{ __('Une extension d\'un seul fichier, sans dépendance : pas de Composer, pas d\'extension tierce, pas de JavaScript. Copiez-la dans wp-content/plugins/dolinews-feed/ et activez-la, ou collez son contenu à la fin du functions.php de votre thème enfant - l\'une ou l\'autre, jamais les deux.') }}</p>
                </div>

                <p class="mt-4">
                    <a class="btn btn-primary" href="{{ $pluginUrl }}">{{ __('Télécharger l\'extension (dolinews-feed.php)') }}</a>
                </p>

                <div class="prose-dolinews mt-4">
                    <p>{{ __('Le code court s\'écrit ensuite dans n\'importe quel bloc, widget de texte ou gabarit :') }}</p>
                </div>

                <pre class="code-block mt-3"><code>[dolinews editor="mon-editeur" limit="5"]

[dolinews project="mon-module" limit="3" layout="cards"]

[dolinews focus="security" dolibarr="22" title="{{ __('Correctifs de sécurité') }}"]</code></pre>

                <div class="prose-dolinews mt-4">
                    <p>{{ __('Le slug de l\'éditeur ou du projet se lit dans l\'adresse de sa fiche sur ce site. Les attributs disponibles :') }}</p>

                    <ul>
                        <li><code>editor</code>, <code>project</code>, <code>focus</code>, <code>dolibarr</code>, <code>q</code> - {{ __('les mêmes filtres que le fil.') }}</li>
                        <li><code>maturity</code> - {{ __('une liste séparée par des virgules, par exemple beta,rc. Sans cet attribut, seules les versions stables sont listées.') }}</li>
                        <li><code>locale</code> - {{ __('la langue de lecture, en locale de contenu : fr_FR, es_ES, de_DE...') }}</li>
                        <li><code>limit</code>, <code>layout</code>, <code>summary</code>, <code>title</code>, <code>link_text</code> - {{ __('la présentation : nombre d\'annonces, liste ou cartes, résumé affiché ou non, titre et libellé du lien.') }}</li>
                        <li><code>cache</code> - {{ __('la durée du cache local en secondes, 900 par défaut. La dernière réponse connue est conservée une semaine et sert si ce site devient injoignable.') }}</li>
                    </ul>

                    <p>{{ __('Les pastilles - plage Dolibarr, maturité et son ancienneté, nature de la publication, langue de l\'annonce - sont écrites par le service dans la langue demandée. L\'extension n\'en traduit aucune et reste correcte dans les dix langues.') }}</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('Les flux, pour tout le reste') }}</h2>

                <div class="prose-dolinews mt-3">
                    <p>{{ __('Hors WordPress, les deux flux génériques servent la même chose et acceptent les mêmes filtres en paramètres d\'adresse.') }}</p>
                </div>

                <pre class="code-block mt-3"><code>{{ $feedUrl }}?editor=mon-editeur

{{ $jsonUrl }}?project=mon-module&amp;focus=security

{{ $feedUrl }}?dolibarr=22&amp;maturity[]=beta</code></pre>

                <div class="prose-dolinews mt-4">
                    <p>{{ __('Le flux RSS se lit par n\'importe quel agrégateur. Le flux JSON suit le format JSON Feed, et chaque entrée y porte en plus un membre _dolinews : les valeurs brutes de l\'annonce - version, maturité, plage Dolibarr, nature, éditeur, projet - et leurs libellés déjà traduits. C\'est ce membre que lit l\'extension WordPress, et ce qu\'il faut lire pour afficher une annonce ailleurs.') }}</p>

                    <p>{{ __('Un dernier point, qui évite une déception : l\'API répond aux mêmes questions mais garde un filtre de langue strict, parce que son contrat est figé. Une annonce que personne n\'a traduite dans la langue demandée n\'y figure pas, là où le flux sert sa version d\'origine en signalant sa langue. Pour afficher, prenez le flux ; pour traiter, prenez l\'API.') }}</p>
                </div>

                <pre class="code-block mt-3"><code>{{ $apiUrl }}?editor=mon-editeur&amp;per_page=10</code></pre>

                <p class="mt-4 text-sm">
                    <a class="link" href="{{ route('pages.api') }}">{{ __('Documentation de l\'API') }}</a>
                    @if ($sourceUrl !== '')
                        - <a class="link" href="{{ $sourceUrl }}" rel="nofollow">{{ __('Code source du service et de l\'extension') }}</a>
                    @endif
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('Ce que votre site n\'affichera pas') }}</h2>

                <div class="prose-dolinews mt-3">
                    <p>{{ __('Ce service dit ce qui a été annoncé, et quand ; jamais l\'état courant d\'un module. Une intégration qui écrirait "module compatible avec la version 22" affirmerait ce que personne n\'a déclaré : la plage affichée est celle que portait l\'annonce, à sa date, et la date est affichée à côté.') }}</p>

                    <p>{{ __('Pour la même raison, une maturité n\'est jamais montrée sans son ancienneté - personne ne revient dire qu\'une version est sortie de sa phase d\'essai - et les versions d\'essai sont absentes tant qu\'on ne les demande pas.') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
