#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Writes ONE project sheet of a DoliNews instance from a Markdown file.
 *
 * The sheet is the permanent half of what the service holds: the feed
 * says what was announced and when, the sheet says what the project
 * is (SPEC D1). It is also the half nobody ever rewrites, which is how
 * a catalogue ends up with a hundred sheets carrying the one sentence
 * a module descriptor happened to hold.
 *
 *   php vendor/bin/publish-project-sheet.php fiche.md
 *
 * Same shape as publish-article.php, deliberately: a header between two
 * --- lines, the body in Markdown underneath.
 *
 *   ---
 *   project: monmodule
 *   name: "MonModule"
 *   summary: "Ce que fait le module, en une phrase."
 *   locale: fr_FR
 *   license: GPL-3.0-or-later
 *   link_doc: https://doc.example.org/monmodule/
 *   link_demo: https://demo.example.org/monmodule/
 *   logo: images/logo.png
 *   gallery: screenshots/accueil.png | Page d'accueil du module
 *   gallery: screenshots/liste.png | Liste des relances
 *   ---
 *
 *   ## Présentation
 *
 *   Ce que le module apporte, et à qui.
 *
 *   ## Fonctionnalités
 *
 *   - la première
 *   - la deuxième
 *
 * WHAT A SHEET SAYS, AND WHAT IT MUST NOT. It never states a Dolibarr
 * compatibility (SPEC D1): a sheet is persistent, so a version written
 * on it becomes false on its own, where an announcement carries its
 * date. Write "Dolibarr 20 et supérieur" in an announcement, never
 * here. The script refuses a sheet that tries.
 *
 * ON LENGTH. A sheet presents a project, it does not document it: the
 * documentation lives behind the sheet's doc link, where its author
 * maintains it. The instance bounds the description, 8000 characters by
 * default, and this script says so before the network does.
 *
 * ON TRANSLATIONS. A file named fiche.en_US.md next to fiche.md is sent
 * as the English version of the sheet. A sheet translation goes through
 * no review: it states nothing dated, and it is the editor's own text
 * in another language (SPEC 4.2). The service translates the missing
 * languages by itself when the editor asked for it (SPEC 5.7), so these
 * files are for the ones you want written by hand.
 *
 * ON LINKS. One header key per link, link_<type>: <url>, the type being
 * one of LINK_TYPES. The service keeps every link it is sent, duplicates
 * included, so only the links the sheet does not carry yet are sent: the
 * script can be run again without piling them up. A link removed from
 * the file stays on the sheet; it is removed from the account.
 *
 * ON IMAGES. logo: <path> sets the logo of the sheet; each gallery:
 * <path> | <caption> line adds one screenshot, in file order, the
 * caption (255 characters at most) being optional. Paths are relative
 * to the sheet file. SVG is refused, as by the service. An image is
 * recognised by the sha256 of the file itself, which the service keeps
 * as source_hash: one already on the sheet is not uploaded again, and
 * only a changed caption or place is rewritten. An image removed from
 * the file stays on the sheet; take it out with
 * DELETE /projects/<slug>/gallery/<media_id>. The instance bounds the
 * gallery, ten images by default.
 *
 * A screenshot of Dolibarr almost always carries real data - third
 * parties, amounts, addresses - and the service publishes it as sent:
 * take screenshots on demonstration data only (SPEC 7).
 *
 * Usage:
 *   php vendor/bin/publish-project-sheet.php <fichier.md> [--dry-run]
 *                                         [--editor=slug] [--no-translations]
 *   php vendor/bin/publish-project-sheet.php <fichier.md> --check
 *   php vendor/bin/publish-project-sheet.php --init <fichier.md> --module=<chemin>
 *   php vendor/bin/publish-project-sheet.php --help
 *
 * Environment:
 *   DOLINEWS_API_TOKEN     personal token of the contributor account (required)
 *   DOLINEWS_API_BASE      API root, to aim another instance
 *   DOLINEWS_EDITOR_EMAIL  contact address, only used to create a first editor
 *   DOLINEWS_EDITOR_NAME   name of that editor
 */

require_once __DIR__.'/../lib/dolinews-client.php';
require_once __DIR__.'/../lib/dolinews-module.php';

/** Base URL of the API, without trailing slash. */
define('API_BASE', getenv('DOLINEWS_API_BASE') ?: 'https://dolinews.com/api/v1');

/** Personal token of the contributor account (Authorization: Bearer). */
define('API_TOKEN', getenv('DOLINEWS_API_TOKEN') ?: '');

define('EDITOR_NAME', getenv('DOLINEWS_EDITOR_NAME') ?: '');
define('EDITOR_CONTACT_EMAIL', getenv('DOLINEWS_EDITOR_EMAIL') ?: '');

/** Locale of the sheet when the header does not say. */
const DEFAULT_LOCALE = 'fr_FR';

/** Content locales the service accepts. */
const LOCALES = ['fr_FR', 'en_US', 'es_ES', 'de_DE', 'it_IT', 'pt_PT',
    'nl_NL', 'pl_PL', 'ro_RO', 'el_GR'];

/** Limits of the sheet endpoints, mirrored to report them in place. */
const MAX_NAME = 150;
const MAX_SUMMARY = 255;
const MAX_LICENSE = 50;
const MAX_LINK_URL = 2048;

/** Link types the sheet endpoint accepts, header key link_<type>. */
const LINK_TYPES = ['dolistore', 'shop', 'demo', 'doc', 'repo', 'support', 'other'];

/** Bitmap extensions the service decodes; anything else is refused here. */
const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

/** Caption limit of a gallery image. */
const MAX_CAPTION = 255;

/** Said before any image leaves the machine (SPEC 7). */
const SCREENSHOT_WARNING = 'Attention : une capture de Dolibarr contient très souvent des données réelles '
    .'(tiers, montants, adresses), et le service la publie telle quelle. '
    .'Ne publiez que des captures faites sur des données de démonstration.';

exit(main(array_slice($argv, 1)));

/**
 * @param  list<string>  $args
 */
function main(array $args): int
{
    $options = parseArguments($args);

    if ($options['help']) {
        usage();

        return 0;
    }

    if ($options['init']) {
        return writeSkeleton($options);
    }

    $path = $options['file'];

    if ($path === '') {
        usage();

        return 1;
    }

    [$meta, $body, $images] = readSheetFile($path);

    say('Fichier : '.$path);
    say('Fiche   : '.$meta['name'].' ('.$meta['project'].')');

    // Everything above needs neither network nor token, which is what
    // --check exists for: a file validated in a pipeline, or by someone
    // who has not opened an account yet.
    if ($options['check']) {
        return reportCheck($path, $meta, $body, $images, $options);
    }

    if (API_TOKEN === '') {
        fail('DOLINEWS_API_TOKEN est vide : exportez le jeton de votre compte contributeur.');
    }

    dolinews_configure(API_BASE, API_TOKEN);

    // The editors of the account come with its profile, as for
    // publish-article.php. GET /editors is the public directory: reading
    // it here found "no editor" and tried to create one the account owned.
    $profile = requireContributorProfile();

    $editors = $profile['editors'] ?? [];
    $editor = resolveEditor($editors, $options['dryRun'], [
        'slug' => $options['editor'],
        'name' => EDITOR_NAME,
        'contact_email' => EDITOR_CONTACT_EMAIL,
    ]);

    $existing = apiGetOrNull('/projects/'.rawurlencode($meta['project']));
    $missing = missingLinks(sheetLinks($meta), $existing['links'] ?? []);
    $plan = imagePlan($images, $existing);

    if ($options['dryRun']) {
        say($existing === null
            ? 'Simulation : la fiche "'.$meta['project'].'" serait créée.'
            : 'Simulation : la fiche "'.$meta['project'].'" serait corrigée.');

        foreach ($missing as $type => $url) {
            say('Simulation : lien '.$type.' à ajouter : '.$url);
        }

        reportImagePlan($plan, 'Simulation : ');

        return 0;
    }

    $slug = $existing === null
        ? createSheet($editor, $meta, $body)
        : updateSheet($meta, $body);

    sendLinks($slug, $missing);
    sendImages($slug, $editor, $meta, $plan);

    if (! $options['noTranslations']) {
        sendTranslations($path, $slug);
    }

    say('Fiche en ligne : '.rtrim(str_replace('/api/v1', '', API_BASE), '/').'/fr/projets/'.$slug);

    return 0;
}

/**
 * Create the sheet and return its slug.
 *
 * @param  array<string, mixed>  $editor
 * @param  array<string, string>  $meta
 */
function createSheet(array $editor, array $meta, string $body): string
{
    $created = apiPost('/projects', [
        'editor_id' => $editor['id'],
        'name' => $meta['name'],
        'locale' => $meta['locale'],
        'summary' => $meta['summary'],
        'description' => $body !== '' ? $body : null,
        'license' => $meta['license'] !== '' ? $meta['license'] : null,
    ])['data'] ?? [];

    $slug = (string) ($created['slug'] ?? $meta['project']);

    say('Fiche créée : '.$slug);

    // The slug is derived from the name by the service, so a header
    // naming another one would send every later run to the wrong sheet.
    if ($slug !== $meta['project']) {
        say('Attention : le service a attribué le slug "'.$slug.'". '
            .'Corrigez "project:" dans '.$meta['project'].' pour que les prochains envois visent cette fiche.');
    }

    return $slug;
}

/**
 * Rewrite an existing sheet and return its slug.
 *
 * @param  array<string, string>  $meta
 */
function updateSheet(array $meta, string $body): string
{
    apiPatch('/projects/'.rawurlencode($meta['project']), [
        'name' => $meta['name'],
        'locale' => $meta['locale'],
        'summary' => $meta['summary'],
        'description' => $body !== '' ? $body : null,
        'license' => $meta['license'] !== '' ? $meta['license'] : null,
    ]);

    say('Fiche corrigée : '.$meta['project']);

    return $meta['project'];
}

/**
 * Send the translations sitting next to the file.
 */
function sendTranslations(string $path, string $slug): void
{
    $directory = dirname($path);
    $base = basename($path, '.md');

    foreach (LOCALES as $locale) {
        $candidate = $directory.'/'.$base.'.'.$locale.'.md';

        if (! is_file($candidate)) {
            continue;
        }

        [$meta, $body] = readSheetFile($candidate, isTranslation: true);

        apiPost('/projects/'.rawurlencode($slug).'/translations', [
            'locale' => $locale,
            'name' => $meta['name'],
            'summary' => $meta['summary'],
            'description' => $body !== '' ? $body : null,
        ]);

        say('Traduction '.$locale.' déposée.');
    }
}

/**
 * Read one sheet file: header, body, and every check that needs no
 * network.
 *
 * @return array{0: array<string, string>, 1: string, 2: array{logo: ?string, gallery: list<array{file: string, caption: ?string}>}}
 */
function readSheetFile(string $path, bool $isTranslation = false): array
{
    if (! is_readable($path)) {
        fail('Fichier introuvable ou illisible : '.$path);
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fail('Lecture impossible : '.$path);
    }

    [$meta, $body, $all] = splitFrontMatter($contents, $path);

    return [
        checkMetadata($meta, $path, $isTranslation),
        checkBody($body, $path),
        checkImages($all, $path, $isTranslation),
    ];
}

/**
 * The logo and the gallery the header declares, every file checked
 * without a network: present, readable, a bitmap extension, never SVG,
 * a caption within its limit.
 *
 * @param  array<string, list<string>>  $all  every value of every header key
 * @return array{logo: ?string, gallery: list<array{file: string, caption: ?string}>}
 */
function checkImages(array $all, string $path, bool $isTranslation): array
{
    $images = ['logo' => null, 'gallery' => []];

    if ($isTranslation) {
        foreach (['logo', 'gallery'] as $key) {
            if (isset($all[$key])) {
                fail('En-tête de '.$path.' : "'.$key.'" n\'a rien à faire dans une traduction, '
                    .'les images appartiennent à la fiche source.');
            }
        }

        return $images;
    }

    $logos = $all['logo'] ?? [];

    if (count($logos) > 1) {
        fail('En-tête de '.$path.' : "logo" est écrit '.count($logos).' fois, une fiche n\'a qu\'un logo.');
    }

    if ($logos !== [] && trim($logos[0]) !== '') {
        $images['logo'] = checkImageFile(trim($logos[0]), 'logo', $path);
    }

    foreach ($all['gallery'] ?? [] as $value) {
        $separator = strpos($value, '|');
        $file = trim($separator === false ? $value : substr($value, 0, $separator));
        $caption = $separator === false ? '' : trim(substr($value, $separator + 1));

        if ($file === '') {
            fail('En-tête de '.$path.' : une ligne "gallery" ne nomme aucun fichier, '
                .'attendu "gallery: chemin/capture.png | Légende".');
        }

        if (mb_strlen($caption) > MAX_CAPTION) {
            fail('En-tête de '.$path.' : la légende de '.$file.' fait '.mb_strlen($caption)
                .' caractères, le maximum est '.MAX_CAPTION.'.');
        }

        $resolved = checkImageFile($file, 'gallery', $path);

        foreach ($images['gallery'] as $entry) {
            if ($entry['file'] === $resolved) {
                fail('En-tête de '.$path.' : '.$file.' figure deux fois dans la galerie.');
            }
        }

        $images['gallery'][] = ['file' => $resolved, 'caption' => $caption !== '' ? $caption : null];
    }

    return $images;
}

/**
 * One image file named by the header, resolved against the sheet file.
 */
function checkImageFile(string $file, string $key, string $path): string
{
    $resolved = str_starts_with($file, '/') ? $file : dirname($path).'/'.$file;
    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));

    if ($extension === 'svg') {
        fail('En-tête de '.$path.' : "'.$key.'" nomme '.$file.'. Le SVG est refusé par le service, '
            .'il embarque du script : exportez l\'image en PNG.');
    }

    if (! in_array($extension, IMAGE_EXTENSIONS, true)) {
        fail('En-tête de '.$path.' : "'.$key.'" nomme '.$file.', attendu une image '
            .implode(', ', IMAGE_EXTENSIONS).'.');
    }

    if (! is_file($resolved) || ! is_readable($resolved)) {
        fail('En-tête de '.$path.' : "'.$key.'" nomme '.$file.', introuvable ou illisible '
            .'(chemin relatif au fichier de fiche).');
    }

    return $resolved;
}

/**
 * What has to be sent for the images to match the file, compared on the
 * sha256 of each local file against the source_hash the sheet exposes.
 *
 * @param  array{logo: ?string, gallery: list<array{file: string, caption: ?string}>}  $images
 * @param  array<string, mixed>|null  $existing  sheet payload, null for a sheet to create
 * @return array{logo: ?string, gallery: list<array{file: string, caption: ?string, position: int, media_id: ?int}>, stray: list<array<string, mixed>>}
 */
function imagePlan(array $images, ?array $existing): array
{
    $plan = ['logo' => null, 'gallery' => [], 'stray' => []];

    if ($images['logo'] !== null) {
        $online = $existing['logo']['source_hash'] ?? null;

        if ($online !== hash_file('sha256', $images['logo'])) {
            $plan['logo'] = $images['logo'];
        }
    }

    $onSheet = [];

    foreach ($existing['gallery'] ?? [] as $entry) {
        if (is_string($entry['source_hash'] ?? null)) {
            $onSheet[$entry['source_hash']] = $entry;
        }
    }

    $kept = [];

    foreach ($images['gallery'] as $position => $image) {
        $hash = (string) hash_file('sha256', $image['file']);
        $online = $onSheet[$hash] ?? null;

        if ($online !== null) {
            $kept[$hash] = true;

            // Already there: only a changed caption or place is rewritten.
            if (($online['caption'] ?? null) === $image['caption'] && ($online['position'] ?? null) === $position) {
                continue;
            }
        }

        $plan['gallery'][] = $image + [
            'position' => $position,
            'media_id' => $online === null ? null : (int) $online['media_id'],
        ];
    }

    foreach ($existing['gallery'] ?? [] as $entry) {
        if (! isset($kept[$entry['source_hash'] ?? ''])) {
            $plan['stray'][] = $entry;
        }
    }

    return $plan;
}

/**
 * Say what the image plan holds.
 *
 * @param  array{logo: ?string, gallery: list<array{file: string, caption: ?string, position: int, media_id: ?int}>, stray: list<array<string, mixed>>}  $plan
 */
function reportImagePlan(array $plan, string $prefix): void
{
    if ($plan['logo'] !== null) {
        say($prefix.'logo à envoyer : '.basename($plan['logo']));
    }

    foreach ($plan['gallery'] as $image) {
        say($prefix.($image['media_id'] === null
            ? 'capture à envoyer : '.basename($image['file'])
            : 'légende ou place à réécrire : '.basename($image['file'])));
    }

    foreach ($plan['stray'] as $entry) {
        say('Sur la fiche mais absente du fichier, laissée en place : '.($entry['url'] ?? '?')
            .' (DELETE /projects/<slug>/gallery/'.($entry['media_id'] ?? '?').' pour la retirer).');
    }
}

/**
 * Upload what is missing and attach it: logo, then gallery in file order.
 *
 * @param  array<string, mixed>  $editor
 * @param  array<string, string>  $meta
 * @param  array{logo: ?string, gallery: list<array{file: string, caption: ?string, position: int, media_id: ?int}>, stray: list<array<string, mixed>>}  $plan
 */
function sendImages(string $slug, array $editor, array $meta, array $plan): void
{
    $uploads = $plan['logo'] !== null
        || array_filter($plan['gallery'], static fn (array $image): bool => $image['media_id'] === null) !== [];

    if ($uploads) {
        say(SCREENSHOT_WARNING);
    }

    if ($plan['logo'] !== null) {
        $media = apiUpload('/media', $plan['logo'], [
            'editor_id' => (string) $editor['id'],
            'alt' => $meta['name'],
        ]);
        apiJson('PUT', '/projects/'.rawurlencode($slug).'/logo', ['media_id' => (int) $media['id']]);
        say('Logo déposé : '.basename($plan['logo']));
    }

    foreach ($plan['gallery'] as $image) {
        $mediaId = $image['media_id'];

        if ($mediaId === null) {
            $media = apiUpload('/media', $image['file'], [
                'editor_id' => (string) $editor['id'],
                'alt' => $image['caption'] ?? $meta['name'],
            ]);
            $mediaId = (int) $media['id'];
        }

        apiPost('/projects/'.rawurlencode($slug).'/gallery', [
            'media_id' => $mediaId,
            'caption' => $image['caption'],
            'position' => $image['position'],
        ]);

        say(($image['media_id'] === null ? 'Capture ajoutée : ' : 'Capture mise à jour : ').basename($image['file']));
    }

    reportImagePlan(['logo' => null, 'gallery' => [], 'stray' => $plan['stray']], '');
}

/**
 * @param  array<string, string>  $meta
 * @return array<string, string>
 */
function checkMetadata(array $meta, string $path, bool $isTranslation): array
{
    $meta += [
        'project' => '',
        'name' => '',
        'summary' => '',
        'locale' => DEFAULT_LOCALE,
        'license' => '',
    ];

    if (! $isTranslation && $meta['project'] === '') {
        fail('En-tête de '.$path.' : "project" est obligatoire, c\'est le slug de la fiche '
            .'(celui de l\'adresse /projets/<slug>).');
    }

    foreach (['name', 'summary'] as $required) {
        if (trim($meta[$required]) === '') {
            fail('En-tête de '.$path.' : "'.$required.'" est obligatoire.');
        }
    }

    if (mb_strlen($meta['name']) > MAX_NAME) {
        fail('En-tête de '.$path.' : "name" dépasse '.MAX_NAME.' caractères.');
    }

    if (mb_strlen($meta['summary']) > MAX_SUMMARY) {
        fail('En-tête de '.$path.' : "summary" fait '.mb_strlen($meta['summary'])
            .' caractères, le maximum est '.MAX_SUMMARY.'. C\'est une phrase, pas un paragraphe : '
            .'le reste appartient au corps de la fiche.');
    }

    if (mb_strlen($meta['license']) > MAX_LICENSE) {
        fail('En-tête de '.$path.' : "license" dépasse '.MAX_LICENSE.' caractères.');
    }

    if (! in_array($meta['locale'], LOCALES, true)) {
        fail('En-tête de '.$path.' : "locale" vaut "'.$meta['locale'].'", '
            .'attendu une locale de contenu parmi '.implode(', ', LOCALES).'.');
    }

    foreach ($meta as $key => $value) {
        if (! str_starts_with($key, 'link_')) {
            continue;
        }

        if ($isTranslation) {
            fail('En-tête de '.$path.' : "'.$key.'" n\'a rien à faire dans une traduction, '
                .'les liens appartiennent à la fiche source.');
        }

        $type = substr($key, 5);

        if (! in_array($type, LINK_TYPES, true)) {
            fail('En-tête de '.$path.' : "'.$key.'" n\'est pas un type de lien connu, '
                .'attendu link_ suivi de '.implode(', ', LINK_TYPES).'.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            fail('En-tête de '.$path.' : "'.$key.'" vaut "'.$value.'", attendu une adresse http ou https.');
        }

        if (strlen($value) > MAX_LINK_URL) {
            fail('En-tête de '.$path.' : "'.$key.'" dépasse '.MAX_LINK_URL.' caractères.');
        }
    }

    return $meta;
}

/**
 * The links the header declares, type => url, in LINK_TYPES order.
 *
 * @param  array<string, string>  $meta
 * @return array<string, string>
 */
function sheetLinks(array $meta): array
{
    $links = [];

    foreach (LINK_TYPES as $type) {
        if (($meta['link_'.$type] ?? '') !== '') {
            $links[$type] = $meta['link_'.$type];
        }
    }

    return $links;
}

/**
 * The declared links the sheet does not carry yet, compared on type and url.
 *
 * @param  array<string, string>  $declared
 * @param  array<int, array<string, mixed>>  $carried  links of the sheet payload
 * @return array<string, string>
 */
function missingLinks(array $declared, array $carried): array
{
    $present = [];

    foreach ($carried as $link) {
        $present[($link['type'] ?? '').' '.($link['url'] ?? '')] = true;
    }

    return array_filter(
        $declared,
        static fn (string $url, string $type): bool => ! isset($present[$type.' '.$url]),
        ARRAY_FILTER_USE_BOTH,
    );
}

/**
 * Send the declared links the sheet does not carry yet.
 *
 * @param  array<string, string>  $missing
 */
function sendLinks(string $slug, array $missing): void
{
    foreach ($missing as $type => $url) {
        apiPost('/projects/'.rawurlencode($slug).'/links', ['type' => $type, 'url' => $url]);
        say('Lien '.$type.' ajouté : '.$url);
    }
}

/**
 * The body of the sheet, bounded and free of anything dated.
 */
function checkBody(string $body, string $path): string
{
    $body = trim($body);

    if (mb_strlen($body) > MAX_DESCRIPTION) {
        fail('Corps de '.$path.' : '.mb_strlen($body).' caractères, le maximum est '
            .MAX_DESCRIPTION.'. Une fiche présente le projet ; sa documentation vit '
            .'derrière le lien de type doc de la fiche, où son auteur la tient à jour.');
    }

    foreach (DATED_CLAIMS as $pattern) {
        if (preg_match($pattern, $body) === 1) {
            fail('Corps de '.$path.' : la fiche mentionne une version de Dolibarr. '
                .'Une fiche est persistante, donc une compatibilité écrite ici devient fausse '
                .'toute seule : elle appartient à l\'annonce, qui porte sa date (SPEC D1).');
        }
    }

    return $body;
}

/**
 * Report what the file says and what it would do, without a network.
 *
 * @param  array<string, string>  $meta
 * @param  array{logo: ?string, gallery: list<array{file: string, caption: ?string}>}  $images
 * @param  array<string, mixed>  $options
 */
function reportCheck(string $path, array $meta, string $body, array $images, array $options): int
{
    say('Résumé  : '.mb_strlen($meta['summary']).' caractères sur '.MAX_SUMMARY);
    say('Corps   : '.mb_strlen($body).' caractères sur '.MAX_DESCRIPTION);
    say('Langue  : '.$meta['locale']);

    foreach (sheetLinks($meta) as $type => $url) {
        say('Lien    : '.$type.' '.$url);
    }

    if ($images['logo'] !== null) {
        say('Logo    : '.$images['logo']);
    }

    foreach ($images['gallery'] as $image) {
        say('Capture : '.$image['file'].($image['caption'] !== null ? ' | '.$image['caption'] : ''));
    }

    if ($images['logo'] !== null || $images['gallery'] !== []) {
        say(SCREENSHOT_WARNING);
    }

    if ($body === '') {
        say('Avertissement : la fiche n\'a pas de description. Une ligne de résumé seule '
            .'est ce que ce script existe pour remplacer.');
    }

    $found = 0;

    if (! $options['noTranslations']) {
        $directory = dirname($path);
        $base = basename($path, '.md');

        foreach (LOCALES as $locale) {
            if (is_file($directory.'/'.$base.'.'.$locale.'.md')) {
                readSheetFile($directory.'/'.$base.'.'.$locale.'.md', isTranslation: true);
                $found++;
            }
        }
    }

    say('Traductions voisines : '.$found);
    say('Fichier valide. Rien n\'a été envoyé.');

    return 0;
}

/**
 * Write a skeleton filled from the user documentation of a module.
 *
 * docs/users/index.md is where a module already describes itself for
 * whoever installs it: its front matter carries a one-line description,
 * and its first sections say what the module does. That is exactly what
 * a sheet needs, and retyping it produces a worse text.
 *
 * @param  array<string, mixed>  $options
 */
function writeSkeleton(array $options): int
{
    $module = readModuleDescriptor($options['module']);
    $documented = readModuleDocumentation($options['module']);

    $name = $module['name'] !== '' ? $module['name'] : basename($options['module']);
    $summary = $documented['summary'] !== '' ? $documented['summary'] : $module['summary'];

    if (mb_strlen($summary) > MAX_SUMMARY) {
        $summary = mb_substr($summary, 0, MAX_SUMMARY - 1);
    }

    $body = $documented['body'] !== ''
        ? $documented['body']
        : "## Présentation\n\nCe que le module apporte, et à qui.\n\n## Fonctionnalités\n\n- \n";

    $file = renderSheetFile([
        'project' => $module['slug'] !== '' ? $module['slug'] : slugify($name),
        'name' => $name,
        'summary' => $summary,
        'locale' => DEFAULT_LOCALE,
        'license' => $module['license'] ?? '',
    ], $body);

    $target = $options['file'] !== '' ? $options['file'] : 'fiche.md';

    if (is_file($target)) {
        fail('Le fichier '.$target.' existe déjà : --init ne l\'écrase pas.');
    }

    file_put_contents($target, $file);

    say('Squelette écrit dans '.$target
        .($documented['body'] !== '' ? ' depuis docs/users/index.md.' : '.'));
    say('Relisez-le, puis : php vendor/bin/publish-project-sheet.php '.$target);

    return 0;
}

/**
 * Render a sheet file: header, then body.
 *
 * @param  array<string, string>  $meta
 */
function renderSheetFile(array $meta, string $body): string
{
    $lines = ['---'];

    foreach ($meta as $key => $value) {
        $lines[] = $key.': '.(str_contains($value, ':') ? '"'.$value.'"' : $value);
    }

    $lines[] = '---';
    $lines[] = '';

    return implode("\n", $lines)."\n".trim($body)."\n";
}

/**
 * PATCH, which the shared client does not carry: it was written for a
 * surface that only ever created.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function apiPatch(string $path, array $payload): array
{
    return apiJson('PATCH', $path, $payload);
}

/**
 * A JSON write with any method, failing on a non 2xx answer.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function apiJson(string $method, string $path, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        fail('Encodage JSON impossible pour '.$path.'.');
    }

    // request() answers an array keyed by status and body, as apiPost() reads it
    $response = request($method, $path, $body, ['Content-Type: application/json']);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        fail($method.' '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * @param  list<string>  $args
 * @return array<string, mixed>
 */
function parseArguments(array $args): array
{
    $options = [
        'file' => '',
        'editor' => '',
        'module' => '.',
        'dryRun' => false,
        'check' => false,
        'init' => false,
        'noTranslations' => false,
        'help' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dryRun'] = true;
        } elseif ($arg === '--check') {
            $options['check'] = true;
        } elseif ($arg === '--init') {
            $options['init'] = true;
        } elseif ($arg === '--no-translations') {
            $options['noTranslations'] = true;
        } elseif (str_starts_with($arg, '--editor=')) {
            $options['editor'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--module=')) {
            $options['module'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '-')) {
            fail('Option inconnue : '.$arg.'. Lancez --help.');
        } else {
            $options['file'] = $arg;
        }
    }

    return $options;
}

function usage(): void
{
    say(<<<'TXT'
    Dépose ou corrige UNE fiche projet DoliNews depuis un fichier Markdown.

      php vendor/bin/publish-project-sheet.php fiche.md
      php vendor/bin/publish-project-sheet.php fiche.md --check
      php vendor/bin/publish-project-sheet.php fiche.md --dry-run
      php vendor/bin/publish-project-sheet.php --init fiche.md --module=/chemin/du/module

    Options :
      --check             valide le fichier sans réseau ni jeton
      --dry-run           dit ce qui serait fait, n'écrit rien
      --editor=slug       éditeur à utiliser si le compte en a plusieurs
      --no-translations   ignore les fichiers fiche.<locale>.md voisins
      --init              écrit un squelette depuis docs/users/index.md du module
      --help              cette aide

    Le fichier porte son en-tête entre deux lignes --- :

      ---
      project: monmodule
      name: "MonModule"
      summary: "Ce que fait le module, en une phrase."
      locale: fr_FR
      license: GPL-3.0-or-later
      logo: images/logo.png
      gallery: screenshots/accueil.png | Page d'accueil du module
      ---

      ## Présentation

      Ce que le module apporte, et à qui.

    logo et gallery nomment des images relatives au fichier de fiche, une ligne
    gallery par capture, légende facultative après |. Une image déjà en ligne
    n'est pas renvoyée. Une capture de Dolibarr contient très souvent des
    données réelles : ne publiez que des captures faites sur des données de
    démonstration.

    Une fiche ne dit JAMAIS une compatibilité Dolibarr : elle est persistante,
    donc une version écrite ici devient fausse toute seule. Cela appartient à
    l'annonce, qui porte sa date.

    Variables d'environnement :
      DOLINEWS_API_TOKEN   jeton personnel du compte contributeur (obligatoire)
      DOLINEWS_API_BASE    racine de l'API, pour viser une autre instance
    TXT);
}
