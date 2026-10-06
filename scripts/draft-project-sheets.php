<?php

declare(strict_types=1);

/**
 * Drafts one sheet file per module of an existing catalogue, from the
 * documentation each module already carries.
 *
 * The catalogue import wrote the sheets from $this->description of the
 * module descriptor, which is one sentence: a hundred sheets ended up
 * saying in fifty characters what their own docs/users/index.md says in
 * three thousand. This script reads that documentation and writes a
 * sheet file per module, ready to be reread and sent with
 * publish-project-sheet.php.
 *
 *   php scripts/draft-project-sheets.php --manifest=scripts/caprel-catalog.json
 *   php scripts/draft-project-sheets.php --repos=/chemin/des/modules
 *
 * It WRITES NOTHING ONLINE and asks for no token. What comes out is a
 * directory of Markdown files and a report of what is missing, because
 * a sheet is editorial text: a machine that deposited it unread would
 * publish, under an editor's name, whatever a documentation happened to
 * hold - including the dated claims a sheet must never carry (SPEC D1).
 *
 * Usage:
 *   php scripts/draft-project-sheets.php [--manifest=<fichier.json>]
 *                                        [--repos=<repertoire>]
 *                                        [--out=<repertoire>] [--force]
 *   php scripts/draft-project-sheets.php --help
 */

require_once __DIR__.'/lib/dolinews-client.php';
require_once __DIR__.'/lib/dolinews-module.php';

/** Where the drafts land, one file per module. */
const DEFAULT_OUT = 'fiches';

/** Below this, what the documentation gave is not a presentation. */
const THIN_BODY = 400;

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

    $modules = $options['manifest'] !== ''
        ? modulesFromManifest($options['manifest'])
        : modulesFromDirectory($options['repos']);

    if ($modules === []) {
        fail('Aucun module à traiter : donnez --manifest=<fichier.json> ou --repos=<repertoire>.');
    }

    if (! is_dir($options['out']) && ! mkdir($options['out'], 0o775, true) && ! is_dir($options['out'])) {
        fail('Répertoire de sortie impossible à créer : '.$options['out']);
    }

    $written = [];
    $derived = [];
    $thin = [];
    $missing = [];
    $skipped = [];

    foreach ($modules as $module) {
        $target = rtrim($options['out'], '/').'/'.$module['slug'].'.md';

        if (is_file($target) && ! $options['force']) {
            $skipped[] = $module['slug'];

            continue;
        }

        if ($module['path'] === '' || ! is_dir($module['path'])) {
            $missing[] = $module['slug'].' (dépôt absent du disque)';

            continue;
        }

        $documented = readModuleDocumentation($module['path']);
        $descriptor = readModuleDescriptor($module['path']);

        $summary = $documented['summary'] !== '' ? $documented['summary'] : $module['summary'];
        $body = $documented['body'];

        // No documentation at all: the README is the next best source,
        // and it is better than the one sentence already online.
        if ($body === '') {
            $body = readmeBody($module['path']);
        }

        if (trim($body) === '') {
            $missing[] = $module['slug'].' (ni docs/users ni README exploitable)';

            continue;
        }

        if (mb_strlen($body) < THIN_BODY) {
            $thin[] = $module['slug'];
        }

        // A sheet without a summary is refused by the publishing tool,
        // and a module whose documentation carries no description line
        // is exactly the one nobody will come back to write it for. The
        // first sentence of the body is a draft worth rereading, and the
        // report says which sheets got one rather than passing it off as
        // written.
        if (trim($summary) === '') {
            $summary = firstSentence($body);
            $derived[] = $module['slug'];
        }

        file_put_contents($target, renderSheetDraft([
            'project' => $module['slug'],
            'name' => $module['name'] !== '' ? $module['name'] : ($descriptor['name'] ?? $module['slug']),
            'summary' => mb_substr($summary, 0, 255),
            'locale' => 'fr_FR',
            'license' => (string) ($descriptor['license'] ?? ''),
        ], $body));

        $written[] = $module['slug'];
    }

    report($options, $written, $thin, $missing, $skipped, $derived);

    return 0;
}

/**
 * What was produced, and what a human still has to deal with.
 *
 * @param  array<string, mixed>  $options
 * @param  list<string>  $written
 * @param  list<string>  $thin
 * @param  list<string>  $missing
 * @param  list<string>  $skipped
 * @param  list<string>  $derived
 */
function report(array $options, array $written, array $thin, array $missing, array $skipped, array $derived): void
{
    say('');
    say(count($written).' brouillon(s) écrit(s) dans '.$options['out'].'/');

    if ($skipped !== []) {
        say(count($skipped).' fiche(s) déjà présente(s), laissée(s) en place (--force pour réécrire).');
    }

    if ($derived !== []) {
        say('');
        say(count($derived).' résumé(s) tiré(s) de la première phrase du texte, faute de mieux : '
            .'à relire en premier, c\'est la ligne que lit un visiteur avant d\'ouvrir la fiche.');
        say('  '.implode(', ', $derived));
    }

    if ($thin !== []) {
        say('');
        say(count($thin).' fiche(s) dont la documentation donne moins de '.THIN_BODY
            .' caractères : à étoffer à la main avant envoi.');
        say('  '.implode(', ', $thin));
    }

    if ($missing !== []) {
        say('');
        say(count($missing).' module(s) sans matière, à rédiger entièrement :');

        foreach ($missing as $line) {
            say('  '.$line);
        }
    }

    say('');
    say('Rien n\'a été envoyé. Relisez les fichiers, puis pour chacun :');
    say('  php scripts/publish-project-sheet.php '.$options['out'].'/<slug>.md --check');
    say('  php scripts/publish-project-sheet.php '.$options['out'].'/<slug>.md');
}

/**
 * The first sentence of a text, as a summary of last resort.
 */
function firstSentence(string $body): string
{
    // Headings and list markers go first: what opens a documentation is
    // often "## Présentation", which says nothing about the module.
    $plain = (string) preg_replace('/^(#+\s*|[-*]\s+|\d+\.\s+)/m', '', $body);
    $plain = trim((string) preg_replace('/[*_`]/', '', $plain));

    foreach (explode("\n", $plain) as $line) {
        $line = trim($line);

        if (mb_strlen($line) < 30) {
            continue;
        }

        if (preg_match('/^(.{30,250}?[.!?])(\s|$)/u', $line, $matches) === 1) {
            return trim($matches[1]);
        }

        return mb_substr($line, 0, 250);
    }

    return '';
}

/**
 * The modules a catalogue manifest names.
 *
 * @return list<array{slug: string, name: string, summary: string, path: string}>
 */
function modulesFromManifest(string $path): array
{
    if (! is_readable($path)) {
        fail('Manifeste introuvable : '.$path);
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        fail('Manifeste illisible : '.$path);
    }

    $entries = $decoded['entries'] ?? $decoded;
    $modules = [];

    foreach ($entries as $entry) {
        if (! is_array($entry) || ! isset($entry['slug'])) {
            continue;
        }

        $modules[] = [
            'slug' => (string) $entry['slug'],
            'name' => (string) ($entry['name'] ?? ''),
            'summary' => (string) ($entry['summary'] ?? ''),
            'path' => (string) ($entry['repo_path'] ?? ''),
        ];
    }

    return $modules;
}

/**
 * The modules sitting as directories under one root.
 *
 * @return list<array{slug: string, name: string, summary: string, path: string}>
 */
function modulesFromDirectory(string $root): array
{
    if ($root === '' || ! is_dir($root)) {
        fail('Répertoire de dépôts introuvable : '.$root);
    }

    $modules = [];

    foreach (glob(rtrim($root, '/').'/*', GLOB_ONLYDIR) ?: [] as $path) {
        $descriptor = readModuleDescriptor($path);

        $modules[] = [
            'slug' => $descriptor['slug'] !== '' ? $descriptor['slug'] : slugify(basename($path)),
            'name' => $descriptor['name'],
            'summary' => $descriptor['summary'],
            'path' => $path,
        ];
    }

    return $modules;
}

/**
 * The README, stripped of what a sheet does not carry: the badges, the
 * installation instructions and the dated claims.
 */
function readmeBody(string $directory): string
{
    $readme = rtrim($directory, '/').'/README.md';

    if (! is_file($readme)) {
        return '';
    }

    $contents = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($readme));
    $kept = [];

    foreach (explode("\n", $contents) as $line) {
        // The title line of a README is the module name, which the
        // header already carries.
        if (preg_match('/^#\s/', $line) === 1) {
            continue;
        }

        if (preg_match('/^\s*\[!\[/', $line) === 1) {
            continue;
        }

        $dated = false;

        foreach (DATED_CLAIMS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $dated = true;
                break;
            }
        }

        if ($dated) {
            continue;
        }

        $kept[] = $line;
    }

    $body = cleanDocumentationBody(implode("\n", $kept));

    // Cut before whatever documents the installation: a sheet presents.
    $stop = preg_split('/^##\s*(installation|install|configuration|usage|utilisation|licence|license|changelog)/im', $body);

    if (is_array($stop) && $stop !== []) {
        $body = trim((string) $stop[0]);
    }

    return mb_strlen($body) > MAX_DESCRIPTION ? mb_substr($body, 0, MAX_DESCRIPTION) : $body;
}

/**
 * One draft file: the header the publishing tool reads, then the body.
 *
 * @param  array<string, string>  $meta
 */
function renderSheetDraft(array $meta, string $body): string
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
 * @param  list<string>  $args
 * @return array<string, mixed>
 */
function parseArguments(array $args): array
{
    $options = [
        'manifest' => '',
        'repos' => '',
        'out' => DEFAULT_OUT,
        'force' => false,
        'help' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif (str_starts_with($arg, '--manifest=')) {
            $options['manifest'] = substr($arg, 11);
        } elseif (str_starts_with($arg, '--repos=')) {
            $options['repos'] = substr($arg, 8);
        } elseif (str_starts_with($arg, '--out=')) {
            $options['out'] = substr($arg, 6);
        } else {
            fail('Option inconnue : '.$arg.'. Lancez --help.');
        }
    }

    return $options;
}

function usage(): void
{
    say(<<<'TXT'
    Fabrique un brouillon de fiche par module, depuis leur documentation.

      php scripts/draft-project-sheets.php --manifest=scripts/caprel-catalog.json
      php scripts/draft-project-sheets.php --repos=/chemin/des/modules
      php scripts/draft-project-sheets.php --repos=... --out=fiches --force

    Options :
      --manifest=<fichier>  manifeste du catalogue, avec les chemins des dépôts
      --repos=<repertoire>  racine contenant un dépôt de module par sous-dossier
      --out=<repertoire>    où écrire les brouillons (défaut : fiches/)
      --force               réécrit un brouillon déjà présent
      --help                cette aide

    Source du texte, dans l'ordre : docs/users/index.md du module, puis son
    README. Les sections datées - prérequis, compatibilité, installation - sont
    écartées : une fiche est persistante, ce qui est daté appartient à l'annonce.

    Rien n'est envoyé en ligne. Relisez chaque brouillon, puis déposez-le avec
    publish-project-sheet.php.
    TXT);
}
