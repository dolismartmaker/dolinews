<?php

declare(strict_types=1);

/**
 * Submits the SmartInterventions project sheet and its two historical
 * release articles to a DoliNews instance through the public API
 * (SPEC 5.2).
 *
 * The script only uses /api/v1: it never touches the database. It needs
 * a personal token of an account that is a CONTRIBUTOR and a member of
 * the editor publishing SmartInterventions. A token grants the right to
 * submit, never to publish: the four articles land in the review queue,
 * and a moderator (or the super admin during the bootstrap phase)
 * publishes them from the back office.
 *
 * The two announcements are retrospective: 1.0 went online on
 * 26 September 2023 and 2.0 on 23 July 2025. Nothing lets an article
 * carry a past date - published_at is stamped when the review accepts it,
 * and the gap with submitted_at feeds the observed review delay
 * (SPEC 4.3/5.1). Each release date therefore lives in the text, which
 * states it in its first line.
 *
 * Both bodies describe the module as it stood on its release day, never
 * as it stands today (SPEC 2): the 1.0 text is built from the v1.0.4 tag
 * of the repository, the 2.0 text from the state of the v2 branch in
 * July 2025 and from the DoliStore listing of that date.
 *
 * No illustration on purpose. No screenshot of the 2023 interface
 * survives, and the screenshots shipped with the current documentation
 * show the front end rewritten in December 2025 and April 2026, which
 * was not what either version displayed. Illustrating a dated
 * announcement with a later interface would show something that was
 * never there.
 *
 * Not idempotent on articles: re-running it submits them again. The
 * project sheet, its links and its translation are reused when they
 * already exist.
 *
 * Usage:
 *   php scripts/publish-smartinterventions-articles.php [--dry-run]
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

/**
 * Typed links of the sheet (SPEC 4.2). URL shorteners are refused. No
 * dolistore entry: the module is sold there, but its product identifier
 * is not readable from the sources at hand, and an invented URL would
 * outlive the mistake on a persistent sheet.
 */
const PROJECT_LINKS = [
    ['type' => 'repo', 'url' => 'https://inligit.fr/cap-rel/dolibarr/plugin-smartinterventions', 'label' => 'Dépôt git'],
    ['type' => 'shop', 'url' => 'https://shop.cap-rel.fr/product/MOD-Dolibarr-smartInterventions', 'label' => 'Boutique CAP-REL'],
    ['type' => 'doc', 'url' => 'https://doc.cap-rel.fr/smartinterventions/', 'label' => 'Documentation'],
    ['type' => 'support', 'url' => 'https://sav.cap-rel.fr/', 'label' => 'Support'],
];

// ---------------------------------------------------------------------
// Content
// ---------------------------------------------------------------------

/**
 * The project sheet. It carries NO Dolibarr compatibility on purpose
 * (SPEC 4.2): anything dated lives in the feed, on the article.
 */
const PROJECT = [
    'name' => 'SmartInterventions',
    'summary' => 'Interventions de terrain dans Dolibarr : application installable pour le technicien, compte rendu et photos saisis sur place, retour automatique sur la fiche d\'intervention.',
    'license' => 'GPL-3.0-or-later',
    'description' => <<<'MD'
        SmartInterventions prolonge les fiches d'intervention natives de
        Dolibarr par une application installable destinée aux techniciens de
        terrain.

        Le technicien y retrouve les interventions qui lui sont affectées,
        avec l'adresse du client, son numéro de téléphone et le détail de la
        prestation à réaliser. Il rédige son compte rendu sur place, ajoute
        des photos et renvoie le tout dans Dolibarr, où le compte rendu
        rejoint la fiche d'intervention.

        Côté back-office, le module complète le cycle de vie des
        interventions, tient l'agenda des techniciens à jour au fil des
        affectations et prévient l'équipe administrative à chaque compte
        rendu reçu.
        MD,
];

/** The sheet translated for the English locale (SPEC D14). */
const PROJECT_TRANSLATION = [
    'locale' => 'en_US',
    'name' => 'SmartInterventions',
    'summary' => 'Field interventions inside Dolibarr: an installable application for the technician, report and photos captured on site, automatic return onto the intervention record.',
    'description' => <<<'MD'
        SmartInterventions extends the native Dolibarr intervention records
        with an installable application aimed at field technicians.

        The technician finds the interventions assigned to them, with the
        customer address, phone number and the details of the work to carry
        out. The report is written on the spot, photos are attached, and
        everything flows back into Dolibarr onto the intervention record.

        On the back office side, the module rounds out the intervention life
        cycle, keeps the technicians agenda in step with assignments and
        notifies the administrative team on every incoming report.
        MD,
];

/**
 * The articles, in submission order: the most recent release first, the
 * original launch second.
 *
 * The 1.0 entry is typed "announcement" - it announces the project, and
 * focus only makes sense on a release, where the service drops it anyway
 * (SPEC 4.3).
 *
 * dolibarr_min carries the floor that actually applied on the day. For
 * 2.0 that floor came from SmartAuth, which the mobile API requires and
 * which demanded Dolibarr 17 at the time; the module descriptor itself
 * still announced 11, an oversight corrected much later and not worth
 * repeating in an announcement.
 */
const ARTICLES = [
    [
        'type' => 'release',
        'focus' => 'feature_major',
        'version' => '2.0.8',
        'maturity' => 'stable',
        'compat_status' => 'declared',
        'dolibarr_min' => 17,
        'locale' => 'fr_FR',
        'title' => 'SmartInterventions 2.0 : l\'application du technicien entièrement redéveloppée',
        'summary' => 'Version majeure mise en ligne le 23 juillet 2025. L\'application mobile est redéveloppée de bout en bout sur la boîte à outils mobile de CAP-REL : formulaire composé à partir des champs choisis dans l\'administration, photos, signature, brouillons conservés sur l\'appareil, interface traduite avec mode sombre et échelle réglable. S\'y ajoutent les rapports d\'intervention personnalisés à partir de modèles ODT, en version beta.',
        'body' => <<<'MD'
            SmartInterventions 2.0 a été mis en ligne le 23 juillet 2025, en
            version 2.0.8. Cette annonce est rétrospective : elle décrit le
            module tel qu'il est sorti ce jour-là. La ligne 2.0 a continué
            d'évoluer depuis, et les versions suivantes feront l'objet de leurs
            propres annonces.

            ## Une application redéveloppée de bout en bout

            L'application de la ligne 1.0 était rendue par le module lui-même,
            page par page. La 2.0 la remplace par une application écrite sur la
            boîte à outils mobile pour Dolibarr de CAP-REL, la même qui sert aux
            autres applications de terrain de l'éditeur. Elle reste une
            application web installable sur l'écran d'accueil, sans passage par
            un magasin d'applications, mais elle est désormais autonome et parle
            au module par une interface de programmation dédiée.

            L'authentification passe par le module SmartAuth, qui délivre le
            jeton dont l'application se sert pour toutes ses requêtes.

            ## Le formulaire se configure

            Le compte rendu n'est plus un formulaire figé. Les champs présentés
            au technicien sont décrits dans l'administration du module, et
            l'application les compose à la volée : texte, choix dans une liste,
            case à cocher, durée, photos, signature.

            Un compte rendu partiellement rempli est conservé sur l'appareil
            sous forme de brouillon. Le technicien le retrouve au retour dans
            l'application, ce qui vaut aussi bien après une coupure de réseau
            qu'après un rechargement du navigateur.

            ## Réglages et traductions

            L'interface est traduite en français et en anglais, et chaque
            technicien règle son affichage : langue, mode sombre, échelle de
            l'interface. Le thème de l'application suit celui du module.

            ## Rapports personnalisés à partir de modèles ODT

            Nouvelle fonction livrée en beta : produire le rapport
            d'intervention à partir d'un modèle ODT, mis en page par
            l'exploitant dans sa suite bureautique plutôt que dans un gabarit
            fourni. La fonction est annoncée comme beta, et elle est à prendre
            comme telle.

            ## Quatre étapes avant la mise en ligne

            La version est sortie au terme d'un cycle public : 2.0.5 le 6 juin
            2025, 2.0.6 le 13 juin, 2.0.7 le 1er juillet pour la première beta,
            puis 2.0.8 le 23 juillet sur le DoliStore.

            ## Prérequis de cette version

            Dolibarr 17 ou supérieur et PHP 7.0 ou supérieur, le plancher venant
            du module SmartAuth dont l'application a besoin pour
            s'authentifier. Les modules Tiers, Interventions, Produits,
            Services, Tâches planifiées, Facturation et GED sont activés avec
            le module.

            Le module est publié sous GPL v3.
            MD,
        'translation' => [
            'locale' => 'en_US',
            'title' => 'SmartInterventions 2.0: the technician application rewritten from scratch',
            'summary' => 'A major version released on 23 July 2025. The mobile application is rewritten end to end on the CAP-REL mobile toolkit: a form built from the fields picked in the administration, photos, signature, drafts kept on the device, a translated interface with dark mode and adjustable scale. Custom intervention reports from ODT templates come along, in beta.',
            'body' => <<<'MD'
                SmartInterventions 2.0 was released on 23 July 2025, as version
                2.0.8. This announcement is retrospective: it describes the module
                as it shipped on that day. The 2.0 line has kept moving since, and
                later versions will get announcements of their own.

                ## An application rewritten end to end

                The application of the 1.0 line was rendered by the module itself,
                page by page. Version 2.0 replaces it with an application written on
                the CAP-REL mobile toolkit for Dolibarr, the same one behind the
                other field applications of the publisher. It remains a web
                application installable on the home screen, with no app store
                involved, but it now stands on its own and talks to the module
                through a dedicated programming interface.

                Authentication goes through the SmartAuth module, which issues the
                token the application uses for every request.

                ## The form is configurable

                The report is no longer a fixed form. The fields shown to the
                technician are described in the module administration, and the
                application composes them on the fly: text, pick from a list,
                checkbox, duration, photos, signature.

                A partially filled report is kept on the device as a draft. The
                technician finds it again on the next visit to the application,
                which covers a network outage as much as a browser reload.

                ## Settings and translations

                The interface is translated into French and English, and each
                technician adjusts their display: language, dark mode, interface
                scale. The application theme follows the module one.

                ## Custom reports from ODT templates

                A new function, shipped as beta: producing the intervention report
                from an ODT template, laid out by the operator in an office suite
                rather than in a supplied skeleton. The function is announced as
                beta, and should be taken as such.

                ## Four steps before release

                The version came out at the end of a public cycle: 2.0.5 on 6 June
                2025, 2.0.6 on 13 June, 2.0.7 on 1 July for the first beta, then
                2.0.8 on 23 July on the DoliStore.

                ## Requirements of this version

                Dolibarr 17 or above and PHP 7.0 or above, the floor coming from
                the SmartAuth module the application needs to authenticate. The
                Thirdparties, Interventions, Products, Services, Scheduled jobs,
                Invoicing and ECM modules are enabled along with the module.

                The module is released under GPL v3.
                MD,
        ],
    ],
    [
        'type' => 'announcement',
        'version' => '1.0.4',
        'maturity' => 'stable',
        'compat_status' => 'declared',
        'dolibarr_min' => 11,
        'locale' => 'fr_FR',
        'title' => 'Lancement de SmartInterventions : le compte rendu d\'intervention rempli sur le terrain',
        'summary' => 'Première version publique de SmartInterventions, mise en ligne le 26 septembre 2023. Une application web installable donne au technicien la liste des interventions qui lui sont affectées, le téléphone du client, l\'adresse ouverte dans son application de cartes, le détail de la prestation, une zone de compte rendu dictable, la durée passée et cinq photos. Le tout remonte sur la fiche d\'intervention. Version 1.0.4, Dolibarr 11 et supérieur.',
        'body' => <<<'MD'
            SmartInterventions a été mis en ligne le 26 septembre 2023, en
            version 1.0.4. Cette annonce est rétrospective : elle décrit le
            module tel qu'il est sorti ce jour-là. La ligne 1.0 a évolué
            jusqu'en 2025, et la refonte 2.0 fait l'objet de sa propre annonce.

            ## Le besoin

            Un technicien en clientèle note son compte rendu sur un carnet,
            prend des photos avec son téléphone, et quelqu'un ressaisit le tout
            dans l'ERP le soir ou le lendemain. La ressaisie coûte du temps,
            perd des détails, et retarde la facturation.

            ## Une application installable, limitée à ses interventions

            Le module publie une application web que le technicien installe sur
            l'écran d'accueil de son téléphone, sur Android comme sur iPhone,
            sans passer par un magasin d'applications. Son périmètre est
            strictement limité aux interventions qui lui sont affectées : elle
            n'ouvre pas le reste de Dolibarr.

            La liste ne montre que les interventions en attente. Celles qui ont
            été validées ou clôturées en disparaissent d'elles-mêmes.

            ## Ce que le technicien a sous la main

            Sur chaque intervention :

            - le nom du client, et son numéro de téléphone cliquable pour
              l'appeler avant d'arriver ;
            - son adresse, qui s'ouvre dans l'application de cartes du
              téléphone ;
            - le détail de la prestation tel qu'il a été saisi dans Dolibarr,
              déplié à la demande quand il est long ;
            - une zone de compte rendu, saisie au clavier ou dictée au
              dictaphone du téléphone ;
            - la durée passée, en minutes ;
            - jusqu'à cinq photos prises sur place.

            ## Sur un chantier sans réseau

            L'application détecte la perte de connexion et travaille sur un
            stockage local. Le compte rendu et les photos sont conservés sur
            l'appareil, puis envoyés au serveur quand le réseau revient.

            ## Le retour dans Dolibarr

            À l'envoi, le compte rendu et les photos rejoignent la fiche
            d'intervention, et l'équipe administrative reçoit un courriel de
            compte rendu. Une option de configuration fait passer l'intervention
            au statut validé dans la foulée.

            L'affectation d'une intervention à un technicien crée l'évènement
            correspondant sur son agenda Dolibarr.

            ## Prérequis de cette version

            Dolibarr 11 ou supérieur, PHP 5.6 ou supérieur. Les modules Tiers,
            Interventions, Produits, Services, Tâches planifiées, Facturation et
            GED sont activés avec le module.

            Le module est publié sous GPL v3.
            MD,
        'translation' => [
            'locale' => 'en_US',
            'title' => 'SmartInterventions launches: the intervention report filled in the field',
            'summary' => 'First public version of SmartInterventions, released on 26 September 2023. An installable web application gives the technician the list of interventions assigned to them, the customer phone number, the address opened in the phone map application, the details of the work, a dictatable report area, the time spent and five photos. All of it flows back onto the intervention record. Version 1.0.4, Dolibarr 11 and above.',
            'body' => <<<'MD'
                SmartInterventions was released on 26 September 2023, as version
                1.0.4. This announcement is retrospective: it describes the module
                as it shipped on that day. The 1.0 line went on until 2025, and the
                2.0 rewrite gets an announcement of its own.

                ## The need

                A technician at a customer site writes the report on a notepad,
                takes pictures with a phone, and somebody keys all of it into the
                ERP that evening or the next day. Re-entering costs time, loses
                details and delays invoicing.

                ## An installable application, limited to one's own interventions

                The module publishes a web application the technician installs on
                the phone home screen, on Android as on iPhone, with no app store
                involved. Its scope is strictly limited to the interventions
                assigned to them: it does not open the rest of Dolibarr.

                The list only shows pending interventions. Those validated or
                closed drop out of it on their own.

                ## What the technician has at hand

                On every intervention:

                - the customer name, and a tappable phone number to call ahead;
                - the address, which opens in the phone map application;
                - the details of the work as entered in Dolibarr, unfolded on
                  demand when they run long;
                - a report area, typed or dictated to the phone voice input;
                - the time spent, in minutes;
                - up to five pictures taken on site.

                ## On a site with no network

                The application detects the loss of connection and works against
                local storage. The report and the pictures are kept on the device,
                then sent to the server once the network is back.

                ## Back into Dolibarr

                On submission, the report and the pictures join the intervention
                record, and the administrative team receives a report email. A
                configuration option moves the intervention to the validated status
                straight away.

                Assigning an intervention to a technician creates the matching
                event on their Dolibarr agenda.

                ## Requirements of this version

                Dolibarr 11 or above, PHP 5.6 or above. The Thirdparties,
                Interventions, Products, Services, Scheduled jobs, Invoicing and
                ECM modules are enabled along with the module.

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

// --- The articles ----------------------------------------------------

foreach (ARTICLES as $definition) {
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
        'body' => $definition['body'],
        'submit' => true,
    ];

    // focus qualifies a release; the service drops it on an announcement
    // (SPEC 4.3), so it is not even sent there.
    if (isset($definition['focus'])) {
        $payload['focus'] = $definition['focus'];
    }

    if ($dryRun) {
        say('Article à soumettre : '.$definition['title'].' ('.strlen($definition['body']).' octets)');
        say('  Traduction à soumettre : '.$definition['translation']['title']);

        continue;
    }

    $article = apiPost('/articles', $payload);
    say('Article soumis : #'.$article['id'].' '.$article['title'].' ['.$article['status'].']');

    $translation = apiPost('/articles/'.$article['id'].'/translations', [
        'locale' => $definition['translation']['locale'],
        'title' => $definition['translation']['title'],
        'summary' => $definition['translation']['summary'],
        'body' => $definition['translation']['body'],
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
