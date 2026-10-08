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

    [$meta, $body] = readSheetFile($path);

    say('Fichier : '.$path);
    say('Fiche   : '.$meta['name'].' ('.$meta['project'].')');

    // Everything above needs neither network nor token, which is what
    // --check exists for: a file validated in a pipeline, or by someone
    // who has not opened an account yet.
    if ($options['check']) {
        return reportCheck($path, $meta, $body, $options);
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

    if ($options['dryRun']) {
        say($existing === null
            ? 'Simulation : la fiche "'.$meta['project'].'" serait créée.'
            : 'Simulation : la fiche "'.$meta['project'].'" serait corrigée.');

        return 0;
    }

    $slug = $existing === null
        ? createSheet($editor, $meta, $body)
        : updateSheet($meta, $body);

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
 * @return array{0: array<string, string>, 1: string}
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

    [$meta, $body] = splitFrontMatter($contents, $path);

    return [checkMetadata($meta, $path, $isTranslation), checkBody($body, $path)];
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

    return $meta;
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
 * @param  array<string, mixed>  $options
 */
function reportCheck(string $path, array $meta, string $body, array $options): int
{
    say('Résumé  : '.mb_strlen($meta['summary']).' caractères sur '.MAX_SUMMARY);
    say('Corps   : '.mb_strlen($body).' caractères sur '.MAX_DESCRIPTION);
    say('Langue  : '.$meta['locale']);

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
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        fail('Encodage JSON impossible pour '.$path.'.');
    }

    // request() answers an array keyed by status and body, as apiPost() reads it
    $response = request('PATCH', $path, $body, ['Content-Type: application/json']);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        fail('PATCH '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
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
      ---

      ## Présentation

      Ce que le module apporte, et à qui.

    Une fiche ne dit JAMAIS une compatibilité Dolibarr : elle est persistante,
    donc une version écrite ici devient fausse toute seule. Cela appartient à
    l'annonce, qui porte sa date.

    Variables d'environnement :
      DOLINEWS_API_TOKEN   jeton personnel du compte contributeur (obligatoire)
      DOLINEWS_API_BASE    racine de l'API, pour viser une autre instance
    TXT);
}
