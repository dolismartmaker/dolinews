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

if (API_TOKEN === '') {
    fail('Renseignez API_TOKEN en tête de script : un jeton personnel obtenu depuis le compte contributeur.');
}

if (! function_exists('curl_init')) {
    fail('L\'extension curl de PHP est requise.');
}

say('Cible : '.API_BASE.($dryRun ? ' (simulation)' : ''));

$profile = apiGet('/profile');

if (($profile['is_contributor'] ?? false) !== true) {
    fail('Ce compte n\'est pas contributeur : il peut lire et s\'abonner, jamais écrire (SPEC 3.1).');
}

$editor = resolveEditor($profile['editors'] ?? [], $dryRun);
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
    $body = expand($definition['body'], $media);

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
        'body' => expand($definition['translation']['body'], $media),
        'submit' => true,
    ]);

    say('Traduction soumise : #'.$translation['id'].' '.$translation['locale']
        .' ['.$translation['status'].']');
}

say('Terminé. Un jeton donne le droit de soumettre, jamais celui de publier :');
say('les articles attendent la revue dans le back-office.');

exit(0);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Pick the editor to publish for: the configured slug, or the only one
 * the account belongs to. Several editors without EDITOR_SLUG is an
 * ambiguity the script refuses to resolve by itself.
 *
 * An account that owns none gets one created here: one never publishes
 * under one's own name, always under an editor, and a run that stopped
 * to send the operator to a web form for a single form would be a poor
 * integration.
 *
 * @param  array<int, array<string, mixed>>  $editors
 * @return array<string, mixed>
 */
function resolveEditor(array $editors, bool $dryRun): array
{
    if ($editors === []) {
        if (EDITOR_CONTACT_EMAIL === '') {
            fail('Ce compte n\'appartient à aucun éditeur, et EDITOR_CONTACT_EMAIL est vide : '
                .'renseignez le courriel de contact en tête de script pour que l\'éditeur "'
                .EDITOR_NAME.'" soit créé.');
        }

        if ($dryRun) {
            say('Éditeur à créer : '.EDITOR_NAME);

            return ['id' => 0, 'slug' => 'a-creer', 'name' => EDITOR_NAME, 'role' => 'owner'];
        }

        $created = apiPost('/editors', array_filter([
            'name' => EDITOR_NAME,
            'contact_email' => EDITOR_CONTACT_EMAIL,
            'website' => EDITOR_WEBSITE,
            'description' => EDITOR_DESCRIPTION,
        ], static fn (string $value): bool => $value !== ''));

        say('Éditeur créé : '.$created['name'].' (#'.$created['id'].'), vous en êtes le propriétaire.');

        // The creating account owns what it just created; the profile
        // is not read again for a single field.
        return $created + ['role' => 'owner'];
    }

    if (EDITOR_SLUG !== '') {
        foreach ($editors as $candidate) {
            if ($candidate['slug'] === EDITOR_SLUG) {
                return $candidate;
            }
        }

        fail('Aucun éditeur de ce compte ne porte le slug '.EDITOR_SLUG.'.');
    }

    if (count($editors) > 1) {
        fail('Ce compte appartient à plusieurs éditeurs : renseignez EDITOR_SLUG parmi '
            .implode(', ', array_column($editors, 'slug')).'.');
    }

    return $editors[0];
}

/**
 * Replace the {{media:key}} and {{alt:key}} placeholders of a body by
 * the URLs and alternative texts of the deposited screenshots. Only
 * media served by the service illustrate an article (SPEC 5.2/7): an
 * unresolved placeholder would produce an image the renderer drops.
 *
 * @param  array<string, array<string, mixed>>  $media
 */
function expand(string $body, array $media): string
{
    foreach ($media as $key => $deposited) {
        $body = str_replace(
            ['{{media:'.$key.'}}', '{{alt:'.$key.'}}'],
            [(string) $deposited['url'], SCREENSHOTS[$key]['alt']],
            $body,
        );
    }

    if (preg_match('/\{\{(media|alt):([a-z0-9-]+)\}\}/', $body, $match) === 1) {
        fail('Référence de capture inconnue dans un corps d\'article : '.$match[0]);
    }

    return $body;
}

/**
 * The slug the service derives from a name, mirrored here to look a
 * sheet up before creating it.
 */
function slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

    return trim($slug, '-');
}

/**
 * @return array<string, mixed>
 */
function apiGet(string $path): array
{
    $response = request('GET', $path);

    if ($response['status'] !== 200) {
        fail('GET '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * Same as apiGet, but a 404 is an expected answer rather than a failure.
 *
 * @return array<string, mixed>|null
 */
function apiGetOrNull(string $path): ?array
{
    $response = request('GET', $path);

    if ($response['status'] === 404) {
        return null;
    }

    if ($response['status'] !== 200) {
        fail('GET '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function apiPost(string $path, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        fail('Encodage JSON impossible pour '.$path.' : '.json_last_error_msg());
    }

    $response = request('POST', $path, $json, [
        'Content-Type: application/json',
    ]);

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        fail('POST '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * Multipart deposit of one image (SPEC 5.2, step one).
 *
 * @param  array<string, string>  $fields
 * @return array<string, mixed>
 */
function apiUpload(string $path, string $file, array $fields): array
{
    $response = request('POST', $path, $fields + [
        'file' => new CURLFile($file, mimeOf($file), basename($file)),
    ]);

    if ($response['status'] !== 201) {
        fail('POST '.$path.' ('.basename($file).') a répondu '.$response['status'].' : '
            .describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * One HTTP call, retried once the write throttle clears: the API allows
 * ten writes per minute and this script makes more than ten.
 *
 * @param  string|array<string, mixed>  $body
 * @param  list<string>  $headers
 * @return array{status: int, body: array<string, mixed>}
 */
function request(string $method, string $path, string|array $body = '', array $headers = []): array
{
    $attempt = 0;

    while (true) {
        $handle = curl_init(API_BASE.$path);

        if ($handle === false) {
            fail('Initialisation curl impossible pour '.$path.'.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge([
                'Authorization: Bearer '.API_TOKEN,
                'Accept: application/json',
            ], $headers),
        ]);

        if ($body !== '' && $body !== []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if (! is_string($raw)) {
            fail($method.' '.$path.' a échoué : '.$error);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            fail($method.' '.$path.' n\'a pas renvoyé du JSON (statut '.$status.') : '
                .substr($raw, 0, 200));
        }

        if ($status !== 429 || $attempt >= 3) {
            return ['status' => $status, 'body' => $decoded];
        }

        $attempt++;
        say('Limite de débit atteinte, nouvelle tentative dans 61 secondes ('.$attempt.'/3).');
        sleep(61);
    }
}

/**
 * Readable form of an error envelope: code, message and details.
 *
 * @param  array<string, mixed>  $body
 */
function describe(array $body): string
{
    $parts = [];

    foreach (['error', 'message'] as $key) {
        if (isset($body[$key]) && is_string($body[$key])) {
            $parts[] = $body[$key];
        }
    }

    foreach ((array) ($body['detail'] ?? $body['errors'] ?? []) as $field => $detail) {
        $parts[] = $field.': '.(is_array($detail) ? implode(' ', $detail) : (string) $detail);
    }

    return $parts === [] ? (string) json_encode($body, JSON_UNESCAPED_UNICODE) : implode(' | ', $parts);
}

/**
 * Declared MIME type of an upload. The service never trusts it: it reads
 * the actual header and re-encodes whatever it finds (SPEC 7).
 */
function mimeOf(string $file): string
{
    $info = @getimagesize($file);

    return $info === false ? 'application/octet-stream' : (string) $info['mime'];
}

function say(string $message): void
{
    fwrite(STDOUT, $message."\n");
}

/**
 * Report the reason on stderr and stop: no silent failure.
 */
function fail(string $reason): never
{
    fwrite(STDERR, 'Échec : '.$reason."\n");

    exit(1);
}
