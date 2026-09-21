<?php

declare(strict_types=1);

/**
 * Submits the OfflinePropale project sheet and its launch announcement to
 * a DoliNews instance through the public API (SPEC 5.2).
 *
 * The script only uses /api/v1: it never touches the database. It needs
 * a personal token of an account that is a CONTRIBUTOR and a member of
 * the editor publishing OfflinePropale. A token grants the right to
 * submit, never to publish: the two articles land in the review queue,
 * and a moderator (or the super admin during the bootstrap phase)
 * publishes them from the back office.
 *
 * The announcement describes the project as it stood on the day it
 * started, 20 February 2026. Nothing lets an article carry a past date:
 * published_at is stamped when the review accepts it, and the gap with
 * submitted_at feeds the observed review delay (SPEC 4.3/5.1). The start
 * date therefore lives in the text, which says so in its first line.
 *
 * Not idempotent on articles: re-running it submits them again. The
 * project sheet, its links and its translation are reused when they
 * already exist.
 *
 * Usage:
 *   php scripts/publish-offlinepropale-launch.php [--dry-run]
 */

require_once __DIR__.'/lib/dolinews-client.php';

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/** Base URL of the API, without trailing slash. */
const API_BASE = 'https://dolinews.com/api/v1';

/** Personal token of the contributor account (Authorization: Bearer). */
const API_TOKEN = '';

/**
 * Slug of the editor to publish for. Empty means: take the only editor
 * the account belongs to, and refuse to guess when there are several.
 */
const EDITOR_SLUG = '';

/**
 * Editor created when the account owns none yet. The name is public:
 * it signs every announcement. The contact address is not published,
 * the review team writes to it; leave it empty and the script stops
 * rather than inventing one.
 */
const EDITOR_NAME = 'CAP-REL';
const EDITOR_CONTACT_EMAIL = '';
const EDITOR_WEBSITE = 'https://www.cap-rel.fr';
const EDITOR_DESCRIPTION = '';

/** Directory holding the OfflinePropale user documentation screenshots. */
const SCREENSHOT_DIR = '/home/groups/devs/code/modules-dolibarr/offlinepropale/docs/users/screenshots';

/**
 * Typed links of the sheet (SPEC 4.2). URL shorteners are refused. No
 * shop entry here: the module has no published store page to point at,
 * and an invented URL would outlive the mistake on a persistent sheet.
 */
const PROJECT_LINKS = [
    ['type' => 'repo', 'url' => 'https://inligit.fr/cap-rel/dolibarr/plugin-offlinepropale', 'label' => 'Dépôt git'],
    ['type' => 'support', 'url' => 'https://sav.cap-rel.fr/', 'label' => 'Support'],
];

/**
 * Screenshots deposited before the articles, keyed by the placeholder
 * the bodies use: {{media:key}} becomes the returned URL, {{alt:key}}
 * the alternative text below.
 *
 * They are taken from the current documentation, which is the only set
 * that exists: the interface has moved since February. Only screens of
 * features present at launch are used here.
 */
const SCREENSHOTS = [
    'liste-offres' => [
        'file' => 'liste-offres.webp',
        'alt' => 'Liste des offres dans l\'application, avec leur état de synchronisation',
    ],
    'creation-rapide' => [
        'file' => 'creation-rapide.webp',
        'alt' => 'Création rapide d\'une offre : grille des produits par catégorie et panier',
    ],
    'apercu-pdf' => [
        'file' => 'apercu-pdf.webp',
        'alt' => 'Aperçu du PDF de pré-devis produit sur l\'appareil',
    ],
    'backoffice-fiche' => [
        'file' => 'backoffice-fiche.webp',
        'alt' => 'Fiche d\'un pré-devis synchronisé dans Dolibarr, avec le prix relevé sur le terrain et le prix actuel du catalogue',
    ],
];

// ---------------------------------------------------------------------
// Content
// ---------------------------------------------------------------------

/**
 * The project sheet. It carries NO Dolibarr compatibility on purpose
 * (SPEC 4.2): anything dated lives in the feed, on the article.
 */
const PROJECT = [
    'name' => 'OfflinePropale',
    'summary' => 'Préparation d\'offres commerciales hors connexion : application installable pour le terrain, synchronisation vers Dolibarr et conversion en proposition commerciale.',
    'license' => 'GPL-3.0-or-later',
    'description' => <<<'MD'
        OfflinePropale est un module Dolibarr accompagné d'une application
        installable qui permet de préparer une offre commerciale chez le
        client, avec ou sans connexion internet.

        Le catalogue de produits et de services, les fiches clients et les
        documents associés sont copiés sur l'appareil. Le commercial compose
        son offre, produit un PDF sur place et le remet au client sans jamais
        dépendre du réseau.

        Au retour en ligne, les offres remontent dans Dolibarr sous forme de
        pré-devis. Le back-office les liste, compare les prix relevés sur le
        terrain aux prix actuels du catalogue, signale les écarts, puis
        convertit l'offre en proposition commerciale.
        MD,
];

/** The sheet translated for the English locale (SPEC D14). */
const PROJECT_TRANSLATION = [
    'locale' => 'en_US',
    'name' => 'OfflinePropale',
    'summary' => 'Offline preparation of commercial offers: an installable field application, synchronisation back into Dolibarr and conversion into a commercial proposal.',
    'description' => <<<'MD'
        OfflinePropale is a Dolibarr module bundled with an installable
        application that prepares a commercial offer at the customer site,
        with or without an internet connection.

        The product and service catalogue, the customer records and their
        attached documents are copied onto the device. The salesperson builds
        the offer, produces a PDF on the spot and hands it over without ever
        depending on the network.

        Once back online, offers flow into Dolibarr as pre-quotes. The back
        office lists them, compares the prices recorded in the field against
        the current catalogue prices, reports the differences, then converts
        the offer into a commercial proposal.
        MD,
];

/**
 * The launch announcement. Type "announcement": focus only makes sense
 * on a release and the service drops it here anyway (SPEC 4.3).
 *
 * maturity is "alpha" and version "1.0.7" because that is what the
 * repository carried on 20 February 2026. An announcement that is not
 * stable is excluded from the feed by default (SPEC 6.2), which is the
 * correct treatment for the text being submitted.
 */
const ARTICLES = [
    [
        'type' => 'announcement',
        'version' => '1.0.7',
        'maturity' => 'alpha',
        'compat_status' => 'declared',
        'dolibarr_min' => 18,
        'locale' => 'fr_FR',
        'screenshots' => ['liste-offres', 'creation-rapide', 'apercu-pdf', 'backoffice-fiche'],
        'title' => 'Lancement d\'OfflinePropale : préparer un devis chez le client, hors connexion',
        'summary' => 'Première version publique d\'OfflinePropale, module Dolibarr accompagné d\'une application installable qui prépare une offre commerciale chez le client sans connexion. Catalogue et fiches clients embarqués, création par grille de catégories, PDF produit sur l\'appareil, synchronisation au retour en ligne puis conversion en proposition commerciale. Version 1.0.7 alpha, Dolibarr 18 et supérieur.',
        'body' => <<<'MD'
            Le projet OfflinePropale a démarré le 20 février 2026. Cette annonce
            décrit ce qu'il contenait ce jour-là, en version 1.0.7 alpha ; le
            module a continué d'évoluer depuis, et les versions suivantes feront
            l'objet de leurs propres annonces.

            ## Le besoin

            Un commercial en clientèle ne dispose pas toujours du réseau, et il
            n'a pas envie de saisir deux fois : une fois sur un carnet, une fois
            le soir dans l'ERP. OfflinePropale lui permet de chiffrer sur place,
            avec les vrais prix du catalogue, et de laisser au client un document
            imprimable avant de quitter le rendez-vous.

            ## Une application installable, sans passage par un magasin

            L'application est une PWA : elle s'installe depuis le navigateur, sur
            Android, sur iOS et sur un poste de bureau, sans dépôt dans un
            magasin d'applications. Chaque appareil est identifié par un
            identifiant unique, ce qui permet de savoir d'où vient chaque offre
            remontée.

            ![{{alt:liste-offres}}]({{media:liste-offres}})

            ## Le catalogue voyage avec l'appareil

            Produits, services, catégories, documents associés, fiches clients et
            contacts, conditions et modes de règlement sont copiés dans le
            stockage local du navigateur. Tout reste consultable sans réseau, y
            compris les photos et les documents techniques attachés aux articles.

            ## Composer l'offre

            La création rapide présente les catégories puis les produits sous
            forme de grille tactile, avec un panier, un pavé numérique pour les
            quantités et une galerie d'images. Les offres types sont enregistrées
            comme modèles et rechargées en un geste. Un client absent du
            catalogue est créé sur place : il sera créé dans Dolibarr à la
            synchronisation.

            ![{{alt:creation-rapide}}]({{media:creation-rapide}})

            ## Le PDF est produit sur l'appareil

            Le document est composé localement, sans appeler le serveur. Il
            reprend l'identité de la société, les lignes de l'offre, les totaux
            et les conditions, et peut être remis au client immédiatement.

            ![{{alt:apercu-pdf}}]({{media:apercu-pdf}})

            ## Retour en ligne : synchronisation puis conversion

            Dès qu'une connexion est disponible, les offres remontent dans
            Dolibarr sous forme de pré-devis. Le back-office les liste et les
            présente avec leur auteur, leur appareil d'origine et leur état.

            Avant la conversion, le module compare chaque ligne au catalogue
            courant : produit devenu indisponible, prix modifié depuis le
            relevé. Les écarts sont affichés ligne à ligne, et la conversion en
            proposition commerciale se fait en connaissance de cause.

            ![{{alt:backoffice-fiche}}]({{media:backoffice-fiche}})

            ## Prérequis de cette version

            Dolibarr 18 ou supérieur, PHP 7.4 ou supérieur. Les modules Tiers,
            Propositions commerciales, Produits, Services, Catégories, Gestion
            de documents et SmartAuth doivent être activés, ce dernier assurant
            l'authentification de l'application.

            Le module est publié sous GPL v3.
            MD,
        'translation' => [
            'locale' => 'en_US',
            'title' => 'OfflinePropale launches: building a quote at the customer site, offline',
            'summary' => 'First public version of OfflinePropale, a Dolibarr module bundled with an installable application that prepares a commercial offer at the customer site without a connection. Embedded catalogue and customer records, category grid entry, PDF produced on the device, synchronisation once back online then conversion into a commercial proposal. Version 1.0.7 alpha, Dolibarr 18 and above.',
            'body' => <<<'MD'
                The OfflinePropale project started on 20 February 2026. This
                announcement describes what it held on that day, as version 1.0.7
                alpha; the module has kept moving since, and later versions will get
                announcements of their own.

                ## The need

                A salesperson at a customer site does not always have a network, and
                has no appetite for entering everything twice: once on a notepad,
                once in the ERP that evening. OfflinePropale prices the job on the
                spot, with the real catalogue prices, and leaves the customer a
                printable document before the meeting ends.

                ## An installable application, with no app store involved

                The application is a PWA: it installs straight from the browser, on
                Android, on iOS and on a desktop, with no submission to an app
                store. Every device carries a unique identifier, which tells where
                each uploaded offer came from.

                ![{{alt:liste-offres}}]({{media:liste-offres}})

                ## The catalogue travels with the device

                Products, services, categories, attached documents, customer records
                and contacts, payment terms and payment modes are copied into the
                browser local storage. Everything stays available without a network,
                including the pictures and technical documents attached to items.

                ## Building the offer

                Quick entry shows categories then products as a touch grid, with a
                cart, a numeric pad for quantities and an image gallery. Recurring
                offers are stored as templates and reloaded in one gesture. A
                customer missing from the catalogue is created on the spot, and will
                be created in Dolibarr on synchronisation.

                ![{{alt:creation-rapide}}]({{media:creation-rapide}})

                ## The PDF is produced on the device

                The document is composed locally, without calling the server. It
                carries the company identity, the offer lines, the totals and the
                terms, and can be handed to the customer straight away.

                ![{{alt:apercu-pdf}}]({{media:apercu-pdf}})

                ## Back online: synchronisation then conversion

                As soon as a connection is available, offers flow into Dolibarr as
                pre-quotes. The back office lists them with their author, their
                originating device and their state.

                Before conversion, the module compares every line against the
                current catalogue: a product gone unavailable, a price changed since
                the field visit. Differences are shown line by line, and the
                conversion into a commercial proposal happens with full knowledge of
                them.

                ![{{alt:backoffice-fiche}}]({{media:backoffice-fiche}})

                ## Requirements of this version

                Dolibarr 18 or above, PHP 7.4 or above. The Thirdparties, Commercial
                proposals, Products, Services, Categories, Document management and
                SmartAuth modules must be enabled, the latter handling the
                authentication of the application.

                The module is released under GPL v3.
                MD,
        ],
    ],
];

// ---------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

dolinews_configure(API_BASE, API_TOKEN);

say('Cible : '.API_BASE.($dryRun ? ' (simulation)' : ''));

$profile = requireContributorProfile();

$editor = resolveEditor($profile['editors'] ?? [], $dryRun, [
    'slug' => EDITOR_SLUG,
    'name' => EDITOR_NAME,
    'contact_email' => EDITOR_CONTACT_EMAIL,
    'website' => EDITOR_WEBSITE,
    'description' => EDITOR_DESCRIPTION,
]);
say('Éditeur : '.$editor['name'].' (#'.$editor['id'].', rôle '.$editor['role'].')');

// --- Project sheet ---------------------------------------------------

$projectSlug = slugify(PROJECT['name']);
$project = apiGetOrNull('/projects/'.$projectSlug);

if ($project !== null) {
    say('Fiche projet déjà présente : '.$project['slug'].' (#'.$project['id'].'), réutilisée.');
} elseif ($dryRun) {
    say('Fiche projet à créer : '.PROJECT['name']);
    $project = ['id' => 0, 'slug' => $projectSlug];
} else {
    $project = apiPost('/projects', PROJECT + ['editor_id' => $editor['id']]);
    say('Fiche projet créée : '.$project['slug'].' (#'.$project['id'].')');
}

$existingLinks = array_column($project['links'] ?? [], 'url');

foreach (PROJECT_LINKS as $link) {
    if (in_array($link['url'], $existingLinks, true)) {
        say('Lien déjà présent : '.$link['url']);

        continue;
    }

    if ($dryRun) {
        say('Lien à ajouter : '.$link['type'].' '.$link['url']);

        continue;
    }

    apiPost('/projects/'.$project['slug'].'/links', $link);
    say('Lien ajouté : '.$link['type'].' '.$link['url']);
}

$existingLocales = array_column($project['translations'] ?? [], 'locale');

if (in_array(PROJECT_TRANSLATION['locale'], $existingLocales, true)) {
    say('Traduction de fiche déjà présente : '.PROJECT_TRANSLATION['locale']);
} elseif ($dryRun) {
    say('Traduction de fiche à créer : '.PROJECT_TRANSLATION['locale']);
} else {
    apiPost('/projects/'.$project['slug'].'/translations', PROJECT_TRANSLATION);
    say('Traduction de fiche créée : '.PROJECT_TRANSLATION['locale']);
}

// --- Step one: the media ---------------------------------------------

// Alternative texts, keyed like the placeholders: the body carries
// {{alt:key}}, the deposited medium only knows its URL.
$alts = array_map(static fn (array $shot): string => $shot['alt'], SCREENSHOTS);

$media = [];

foreach (SCREENSHOTS as $key => $shot) {
    $path = SCREENSHOT_DIR.'/'.$shot['file'];

    if (! is_readable($path)) {
        fail('Capture introuvable ou illisible : '.$path);
    }

    if ($dryRun) {
        say('Capture à déposer : '.$shot['file']);
        $media[$key] = ['id' => 0, 'url' => 'about:blank'];

        continue;
    }

    $deposited = apiUpload('/media', $path, [
        'editor_id' => (string) $editor['id'],
        'alt' => $shot['alt'],
    ]);

    $media[$key] = $deposited;
    say('Capture déposée : '.$shot['file'].' -> #'.$deposited['id'].' ('.$deposited['mime'].', '
        .$deposited['width'].'x'.$deposited['height'].')');
}

// --- Step two: the articles ------------------------------------------

foreach (ARTICLES as $definition) {
    $body = expand($definition['body'], $media, $alts);

    // Only the screenshots this article shows: a medium belongs to one
    // article, and binding them all to the first would make the second
    // depend on the survival of the first.
    $mediaIds = array_map(
        static fn (string $key): int => (int) $media[$key]['id'],
        $definition['screenshots'],
    );

    $payload = [
        'editor_id' => $editor['id'],
        'project_id' => $project['id'],
        'type' => $definition['type'],
        'version' => $definition['version'],
        'maturity' => $definition['maturity'],
        'compat_status' => $definition['compat_status'],
        'dolibarr_min' => $definition['dolibarr_min'],
        'locale' => $definition['locale'],
        'title' => $definition['title'],
        'summary' => $definition['summary'],
        'body' => $body,
        'media_ids' => $mediaIds,
        'submit' => true,
    ];

    if ($dryRun) {
        say('Article à soumettre : '.$definition['title'].' ('.strlen($body).' octets)');
        say('  Traduction à soumettre : '.$definition['translation']['title']);

        continue;
    }

    $article = apiPost('/articles', $payload);
    say('Article soumis : #'.$article['id'].' '.$article['title'].' ['.$article['status'].']');

    $translation = apiPost('/articles/'.$article['id'].'/translations', [
        'locale' => $definition['translation']['locale'],
        'title' => $definition['translation']['title'],
        'summary' => $definition['translation']['summary'],
        'body' => expand($definition['translation']['body'], $media, $alts),
        'submit' => true,
    ]);

    say('Traduction soumise : #'.$translation['id'].' '.$translation['locale']
        .' ['.$translation['status'].']');
}

say('Terminé. Un jeton donne le droit de soumettre, jamais celui de publier :');
say('les articles attendent la revue dans le back-office.');

exit(0);
