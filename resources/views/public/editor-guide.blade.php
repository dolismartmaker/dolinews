@extends('layouts.public')

@section('title', __('Guide de l\'éditeur'))

@section('content')
    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Publier sur DoliNews') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('De l\'ouverture du compte au premier article publié.') }}</p>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Le service dit ce qui a été annoncé, et quand. Il ne dit jamais l\'état courant d\'un module : c\'est votre annonce, avec sa date, qui porte la compatibilité et la maturité. La fiche de votre projet, elle, est permanente et n\'en porte aucune.') }}</p>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Deux choses à savoir avant de commencer. Écrire demande un compte contributeur, c\'est-à-dire une preuve que vous avez réellement contribué à l\'écosystème : lire et s\'abonner n\'en demandent aucune. Et toute publication passe par la revue, sans exception : un jeton d\'API donne le droit de soumettre, jamais celui de publier.') }}</p>

            {{-- Seven steps on one page: the summary says how long the road is
                 before the reader has scrolled it. Plain anchors, no script. --}}
            <nav class="mt-6 flex flex-wrap gap-2 border-t border-slate-100 pt-5 dark:border-slate-800" aria-label="{{ __('Les étapes') }}">
                <a class="chip" href="#etape-1">{{ __('1. Ouvrir le compte') }}</a>
                <a class="chip" href="#etape-2">{{ __('2. Prouver votre contribution') }}</a>
                <a class="chip" href="#etape-3">{{ __('3. Déclarer votre éditeur') }}</a>
                <a class="chip" href="#etape-4">{{ __('4. Créer un jeton d\'API') }}</a>
                <a class="chip" href="#etape-5">{{ __('5. Créer la fiche de votre projet') }}</a>
                <a class="chip" href="#etape-6">{{ __('6. Soumettre votre annonce') }}</a>
                <a class="chip" href="#etape-7">{{ __('7. La revue, puis la suite') }}</a>
            </nav>
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-1" class="text-xl font-semibold tracking-tight">{{ __('1. Ouvrir le compte') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('L\'inscription est libre et donne un compte lecteur. Un courriel de vérification part aussitôt : le lien qu\'il contient est à usage unique et active le compte.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Utilisez de préférence l\'adresse avec laquelle vous signez vos commits. Si cette adresse est déjà connue de l\'index des auteurs, votre compte devient contributeur au moment même où vous vérifiez le courriel, et l\'étape suivante ne vous concerne pas.') }}</p>
            @guest
            <p class="mt-4"><a class="link" href="{{ route('register') }}">{{ __('Créer un compte') }}</a> - <a class="link" href="{{ route('login') }}">{{ __('J\'ai déjà un compte') }}</a></p>
            @endguest
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-2" class="text-xl font-semibold tracking-tight">{{ __('2. Prouver votre contribution') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('La qualification est distincte de l\'authentification : le compte existe d\'abord, la preuve vient ensuite. Le service cherche votre adresse d\'auteur de commits dans un index moissonné sur des clones git des dépôts de référence - jamais sur l\'API d\'une forge, pour que la vérification survive à la disparition d\'un hébergeur.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Votre adresse n\'est jamais conservée en clair, ni dans l\'index, ni sur la preuve : seule son empreinte poivrée est stockée.') }}</p>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Une fois l\'adresse reconnue, il reste à prouver qu\'elle est bien à vous. Deux niveaux :') }}</p>
            <ul class="mt-3 list-disc space-y-2 pl-6 text-slate-700 dark:text-slate-200">
            <li>{{ __('Simple : un code à usage unique est envoyé à l\'adresse de commit, valable une heure, cinq tentatives.') }}</li>
            <li>{{ __('Fort : le service vous donne une phrase à signer avec la clé GPG qui signe vos commits ; vous collez votre clé publique et la signature.') }}</li>
            </ul>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Trois cas où la voie simple ne s\'applique pas :') }}</p>
            <ul class="mt-3 list-disc space-y-2 pl-6 text-slate-700 dark:text-slate-200">
            <li>{{ __('Votre adresse de commit est une redirection anonymisée, du type fournie par les forges : elle ne reçoit pas de courriel, il reste la signature GPG ou la validation manuelle.') }}</li>
            <li>{{ __('Votre adresse n\'apparaît dans aucun dépôt de référence : demandez la validation manuelle à l\'équipe depuis la même page, en expliquant votre situation. C\'est la voie prévue pour les éditeurs sans dépôt public.') }}</li>
            <li>{{ __('Cette adresse est déjà rattachée à un autre compte : une empreinte ne qualifie qu\'un compte, et une preuve révoquée par la modération n\'est jamais rendue par un automatisme.') }}</li>
            </ul>

            @auth
            <p class="mt-4"><a class="link" href="{{ route('account.contribute') }}">{{ __('Vérifier mon rôle de contributeur') }}</a></p>
            @endauth
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-3" class="text-xl font-semibold tracking-tight">{{ __('3. Déclarer votre éditeur') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('L\'éditeur est l\'organisation ou la personne qui publie ; vos fiches projet et vos annonces lui appartiennent. On ne publie jamais en son nom propre : sans éditeur, rien ne peut être soumis. Le formulaire vous est proposé dès votre preuve de contribution acceptée, et se retrouve en bas de la page de vos articles ; une intégration qui part de zéro passe plutôt par POST /editors. Vous en êtes propriétaire et pouvez y rattacher d\'autres comptes contributeurs, qui publieront sous le même nom.') }}</p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Un éditeur possédé par compte : le crédit de publication et le plafond de file se comptent par éditeur. On rejoint les autres sur invitation de leur propriétaire.') }}</p>
            @auth
            <p class="mt-4"><a class="link" href="{{ route('account.articles') }}">{{ __('Mes articles et mon éditeur') }}</a></p>
            @endauth
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-4" class="text-xl font-semibold tracking-tight">{{ __('4. Créer un jeton d\'API') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Le jeton sert à publier depuis votre chaîne d\'intégration, et il est nécessaire à l\'étape suivante. Nommez-le d\'après son usage : le secret ne s\'affiche qu\'une fois, à la création, et se révoque à tout moment.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Il s\'utilise en en-tête sur chaque appel. Commencez par vérifier qu\'il répond :') }}</p>

            <pre class="code-block mt-4"><code>TOKEN="12|..."

curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
     {{ $baseUrl }}/profile</code></pre>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('La réponse porte les deux valeurs dont dépend toute écriture : is_contributor, et la liste de vos éditeurs avec leur identifiant. Notez cet identifiant, les appels suivants le demandent.') }}</p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Débit : 120 lectures et 10 écritures par minute. Un script qui dépose plusieurs images à la suite doit tenir compte de la seconde limite.') }}</p>
            @auth
            <p class="mt-4"><a class="link" href="{{ route('account.tokens') }}">{{ __('Mes jetons d\'API') }}</a></p>
            @endauth
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-5" class="text-xl font-semibold tracking-tight">{{ __('5. Créer la fiche de votre projet') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('La fiche décrit le projet de façon permanente : nom, résumé, description, licence, liens typés vers le dépôt, la documentation, la boutique ou le support. Elle ne porte aucune compatibilité Dolibarr, et c\'est délibéré : une fiche ne vieillit pas avec le temps, donc toute information datée qu\'elle contiendrait deviendrait fausse sans que personne ne la corrige.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('La fiche se crée aujourd\'hui par l\'API uniquement. Un article peut se passer de fiche - ce sera alors une annonce d\'éditeur - mais une sortie de version mérite la sienne.') }}</p>

            <pre class="code-block mt-4"><code># Créer la fiche
curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"editor_id":3,"name":"Mon module",
          "summary":"Ce que fait le module, en une phrase.",
          "license":"GPL-3.0-or-later"}' \
     {{ $baseUrl }}/projects

# Ajouter un lien typé : dolistore, shop, demo, doc, repo, support, other
curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"type":"repo","url":"https://example.org/mon-module","label":"Dépôt git"}' \
     {{ $baseUrl }}/projects/mon-module/links</code></pre>

            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Les raccourcisseurs d\'URL sont refusés, et une fiche Dolistore déjà revendiquée par un autre éditeur déclenche un conflit que la modération tranche.') }}</p>
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-6" class="text-xl font-semibold tracking-tight">{{ __('6. Soumettre votre annonce') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Par le site depuis la page de vos articles, ou par l\'API. Dans les deux cas le contenu est le même, et le résumé est obligatoire : c\'est lui que le lecteur parcourt dans le fil, et son absence est ce qui rend illisibles la plupart des journaux de sortie.') }}</p>

            <ul class="mt-3 list-disc space-y-2 pl-6 text-slate-700 dark:text-slate-200">
            <li>{{ __('Le type distingue la sortie de version de l\'annonce d\'éditeur ; la nature de la publication - sécurité, correctif, fonctionnalité, compatibilité, fin de vie - ne vaut que pour une version.') }}</li>
            <li>{{ __('La maturité va d\'alpha à obsolète. Seules les versions stables apparaissent dans le fil par défaut : le lecteur demande le reste explicitement.') }}</li>
            <li>{{ __('La compatibilité Dolibarr est une plage de versions majeures, et se déclare simplement ou comme réellement éprouvée. Ne déclarez pas éprouvé ce que vous n\'avez pas essayé : le filtre par version en dépend.') }}</li>
            <li>{{ __('Le corps est en Markdown. Les liens sortants sont tous marqués nofollow, et seules les images déposées sur le service peuvent illustrer un article.') }}</li>
            </ul>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Une annonce illustrée se publie en deux temps : déposer les images, puis créer l\'article en citant leurs adresses dans le corps et leurs identifiants dans media_ids. Sans ces identifiants, les images restent orphelines et sont effacées au bout de vingt-quatre heures.') }}</p>

            <pre class="code-block mt-4"><code># 1. Déposer une capture. Elle est ré-encodée, ses métadonnées disparaissent.
curl -H "Authorization: Bearer $TOKEN" \
     -F file=@capture.png -F editor_id=3 -F alt="Écran de configuration" \
     {{ $baseUrl }}/media

# 2. Créer l'article et le soumettre dans le même appel
curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"editor_id":3,"project_id":7,"type":"release",
          "title":"Mon module 2.1","version":"2.1.0","locale":"fr_FR",
          "focus":"bugfix_major","maturity":"stable","dolibarr_min":20,
          "summary":"Ce que cette version change, en quelques lignes.",
          "body":"## Corrections\n\n![Écran](URL_RENVOYEE_A_L_ETAPE_1)",
          "media_ids":[42],"submit":true}' \
     {{ $baseUrl }}/articles</code></pre>

            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Une capture d\'un Dolibarr en service contient presque toujours des données réelles - tiers, montants, adresses. Nettoyez-la avant de l\'envoyer.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Deux limites protègent la file : un rythme de publication par projet, et un plafond d\'articles du même éditeur simultanément en revue. Les traductions échappent au premier.') }}</p>
            <p class="mt-4"><a class="link" href="{{ route('pages.api') }}">{{ __('Documentation complète de l\'API') }}</a></p>
        </div>
    </div>

    <div class="card mx-auto mb-6 max-w-3xl">
        <div class="card-body sm:p-8">
            <h2 id="etape-7" class="text-xl font-semibold tracking-tight">{{ __('7. La revue, puis la suite') }}</h2>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Votre article entre dans la file et trois modérateurs doivent l\'accepter pour qu\'il paraisse. Les échanges se font dans un fil privé entre vous et l\'équipe : vous y répondez, vous corrigez, vous resoumettez. Modifier un article en attente rouvre un tour de revue, car un accord porte sur un texte précis.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Aucun délai n\'est annoncé, et ce n\'est pas une omission : s\'engager sur le temps libre de bénévoles transformerait chaque retard en manquement. La page de la revue affiche le délai réellement observé et l\'attente la plus ancienne. Une annonce de sécurité passe devant.') }}</p>
            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('Une fois publié, un article ne se modifie plus en place : vous proposez une révision, qui repasse par la revue, l\'article porte la mention de sa correction et la version d\'origine reste consultable. Une traduction est un article à part entière, liée à son original, et elle passe elle aussi par la revue ; une annonce non traduite n\'est jamais désavantagée dans la diffusion.') }}</p>
            <p class="mt-4"><a class="link" href="{{ route('review.info') }}">{{ __('Comment fonctionne la revue') }}</a> - <a class="link" href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a></p>
        </div>
    </div>
@endsection
