<?php

declare(strict_types=1);

/**
 * Turns one section of a ChangeLog into a draft article file.
 *
 * Most Dolibarr modules keep a ChangeLog.md whose sections are already
 * the announcement, minus its framing: "## 1.0.5 -- 2025-02-19" then a
 * few lines saying what changed. Retyping that into an article file is
 * the kind of copying that gets a version number wrong.
 *
 *   php scripts/draft-from-changelog.php ChangeLog.md > annonce.md
 *   php scripts/draft-from-changelog.php ChangeLog.md --version=1.0.5
 *   php scripts/draft-from-changelog.php ChangeLog.md annonce.md
 *
 * What comes out is a DRAFT, never a submission: the title has to be
 * finished by a human, the summary reread, and the guessed focus
 * confirmed. It is then checked and sent by publish-article.php.
 *
 * ON THE GUESSED FOCUS. The words of a changelog entry say a lot about
 * the nature of a release, and nothing with certainty. The guess lands
 * in the file as a value to confirm, with a comment saying so, never in
 * a request: announcing a security release that is not one is the sort
 * of mistake the review would catch and hold against the author
 * (SPEC 5.1, règle R5).
 *
 * Usage:
 *   php scripts/draft-from-changelog.php <ChangeLog.md> [fichier.md]
 *                                        [--version=X.Y.Z] [--module=<chemin>]
 *   php scripts/draft-from-changelog.php --help
 */

require_once __DIR__.'/lib/dolinews-client.php';
require_once __DIR__.'/lib/dolinews-module.php';

/** Locale of the produced draft. */
const DRAFT_LOCALE = 'fr_FR';

/**
 * Words that betray the nature of a release, most specific first: an
 * entry that fixes a security hole says "security" and "fix" at once,
 * and the first match must win.
 */
const FOCUS_HINTS = [
    'security' => ['securit', 'sécurit', 'cve-', 'faille', 'xss', 'injection', 'csrf'],
    'eol' => ['end of life', 'fin de vie', 'deprecat', 'obsol'],
    'compat' => ['dolibarr 1', 'dolibarr 2', 'compatib', 'php 8', 'php 7'],
    'doc' => ['documentation', 'readme', 'typo in doc'],
    'bugfix_major' => ['critical', 'critique', 'data loss', 'perte de donn', 'regression', 'régression'],
    'feature_major' => ['new feature', 'nouvelle fonction', 'refonte', 'rewrit', 'redevelop', 'add support'],
    'bugfix_minor' => ['fix', 'corrig', 'correct', 'bug'],
    'feature_minor' => ['add', 'ajout', 'improve', 'améli', 'nouveau', 'nouvelle'],
    'cleanup' => ['cleanup', 'refactor', 'nettoyage'],
];

exit(main(array_slice($argv, 1)));

/**
 * @param  list<string>  $args
 */
function main(array $args): int
{
    $options = parseArguments($args);

    if ($options['help'] || $options['changelog'] === '') {
        usage();

        return $options['help'] ? 0 : 1;
    }

    if (! is_readable($options['changelog'])) {
        fail('ChangeLog introuvable ou illisible : '.$options['changelog']);
    }

    $contents = (string) file_get_contents($options['changelog']);
    $sections = readSections($contents);

    if ($sections === []) {
        fail('Aucune section de version dans '.$options['changelog'].'. Les sections '
            .'attendues commencent par "## " suivi d\'un numéro de version.');
    }

    $section = pickSection($sections, $options['version'], $options['changelog']);
    $module = readModuleDescriptor($options['module']);

    $name = $module['name'] !== '' ? $module['name'] : moduleNameFromChangelog($contents);
    $focus = guessFocus($section['body']);

    $file = renderArticleFile(
        [
            'title' => trim($name.' '.$section['version']),
            'summary' => '',
            'type' => 'release',
            'version' => $section['version'],
            'focus' => $focus,
            'project' => $module['slug'],
            'maturity' => 'stable',
            'compat_status' => 'declared',
            'dolibarr_min' => $module['dolibarr_min'],
            'locale' => DRAFT_LOCALE,
        ],
        renderBody($section),
        [
            'title' => 'complétez : ce que la version apporte, en quelques mots',
            'summary' => 'à écrire : deux phrases au plus, elles servent de chapeau dans le fil',
            'focus' => $focus === '' ? 'à choisir' : 'déduit du texte, à confirmer',
            'compat_status' => 'declared tant que personne n\'a testé',
        ],
    );

    if ($options['output'] === '') {
        echo $file;

        return 0;
    }

    if (file_exists($options['output'])) {
        fail('Ce fichier existe déjà : '.$options['output'].'. Rien n\'a été écrit.');
    }

    if (file_put_contents($options['output'], $file) === false) {
        fail('Écriture impossible : '.$options['output']);
    }

    say('Brouillon écrit : '.$options['output'].' (version '.$section['version'].')');
    say('Complétez le titre et le résumé, puis vérifiez :');
    say('    php scripts/publish-article.php '.$options['output'].' --check');

    return 0;
}

/**
 * @param  list<string>  $args
 * @return array{changelog: string, output: string, version: string, module: string, help: bool}
 */
function parseArguments(array $args): array
{
    $parsed = ['changelog' => '', 'output' => '', 'version' => '', 'module' => '', 'help' => false];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $parsed['help'] = true;
        } elseif (str_starts_with($arg, '--version=')) {
            $parsed['version'] = ltrim(substr($arg, 10), 'vV');
        } elseif (str_starts_with($arg, '--module=')) {
            $parsed['module'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '-')) {
            fail('Option inconnue : '.$arg.'. Lancez --help.');
        } elseif ($parsed['changelog'] === '') {
            $parsed['changelog'] = $arg;
        } elseif ($parsed['output'] === '') {
            $parsed['output'] = $arg;
        } else {
            fail('Trop d\'arguments : '.$arg.'.');
        }
    }

    // The module lives where its changelog does, unless told otherwise.
    if ($parsed['module'] === '' && $parsed['changelog'] !== '') {
        $parsed['module'] = dirname($parsed['changelog']);
    }

    return $parsed;
}

function usage(): void
{
    say('Prépare un brouillon d\'annonce à partir d\'une section de ChangeLog.');
    say('');
    say('  php scripts/draft-from-changelog.php <ChangeLog.md> [fichier.md] [--version=X.Y.Z]');
    say('');
    say('Sans --version, la première section du fichier est retenue, c\'est-à-dire');
    say('la plus récente. Sans fichier de sortie, le brouillon part sur la sortie');
    say('standard.');
    say('');
    say('Le numéro de version, la compatibilité Dolibarr et le slug du projet sont');
    say('lus dans le descripteur du module (core/modules/modXxx.class.php) situé à');
    say('côté du ChangeLog, ou dans --module=<chemin>.');
    say('');
    say('Ce qui sort est un brouillon : le titre et le résumé restent à écrire, et');
    say('le focus déduit du texte est à confirmer. Vérifiez-le ensuite avec');
    say('publish-article.php --check.');
}

/**
 * Cut the changelog into its version sections.
 *
 * Headings seen in the wild: "## 1.0.5", "## 1.0.4 -- 20230926",
 * "## 1.3.1 - 2026-08-26", "## v2.0". The date is kept when there is
 * one, unused for now but visible to whoever reads the draft.
 *
 * @return list<array{version: string, date: string, body: string}>
 */
function readSections(string $contents): array
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
    $sections = [];
    $current = null;

    foreach ($lines as $line) {
        // The separator between version and date is a hyphen here, but
        // changelogs written elsewhere use en and em dashes: matched by
        // code point so this file stays plain ASCII.
        if (preg_match('/^##\s+v?(\d+(?:\.\d+)*)\s*(?:[-\x{2013}\x{2014}]+\s*(.*))?$/u', trim($line), $match) === 1) {
            if ($current !== null) {
                $sections[] = $current;
            }

            $current = [
                'version' => $match[1],
                'date' => normaliseDate(trim($match[2] ?? '')),
                'body' => '',
            ];

            continue;
        }

        if ($current !== null) {
            $current['body'] .= $line."\n";
        }
    }

    if ($current !== null) {
        $sections[] = $current;
    }

    return $sections;
}

/**
 * Dates come as 20230926 or 2025-02-19; anything else is left as read.
 */
function normaliseDate(string $raw): string
{
    if (preg_match('/(\d{4})-?(\d{2})-?(\d{2})/', $raw, $match) === 1) {
        return $match[1].'-'.$match[2].'-'.$match[3];
    }

    return $raw;
}

/**
 * @param  list<array{version: string, date: string, body: string}>  $sections
 * @return array{version: string, date: string, body: string}
 */
function pickSection(array $sections, string $wanted, string $path): array
{
    if ($wanted === '') {
        return $sections[0];
    }

    foreach ($sections as $section) {
        if ($section['version'] === $wanted) {
            return $section;
        }
    }

    fail('Aucune section "'.$wanted.'" dans '.$path.'. Versions trouvées : '
        .implode(', ', array_column($sections, 'version')).'.');
}

/**
 * The module name as the changelog title states it, when no descriptor
 * was found: "# CHANGELOG FACTURX FOR DOLIBARR ERP CRM".
 */
function moduleNameFromChangelog(string $contents): string
{
    if (preg_match('/^#\s*CHANGELOG\s+([A-Za-z0-9_-]+)/mi', $contents, $match) === 1) {
        return ucfirst(strtolower($match[1]));
    }

    return '';
}

/**
 * The nature of the release, as its words suggest it.
 */
function guessFocus(string $body): string
{
    // Hyphens and underscores separate words as spaces do here:
    // "dolibarr-21" and "Dolibarr 21" say the same thing.
    $haystack = (string) preg_replace('/[-_]+/', ' ', mb_strtolower($body));

    foreach (FOCUS_HINTS as $focus => $hints) {
        foreach ($hints as $hint) {
            if (str_contains($haystack, $hint)) {
                return $focus;
            }
        }
    }

    return '';
}

/**
 * The body of the draft: the changelog lines, under a heading, with the
 * date stated in the text when the changelog carried one.
 *
 * The date matters because a back-dated publication shows the day the
 * version came out, and the reader deserves to find it in the text too
 * (SPEC 5.1).
 *
 * @param  array{version: string, date: string, body: string}  $section
 */
function renderBody(array $section): string
{
    $lines = array_values(array_filter(
        array_map('rtrim', explode("\n", trim($section['body']))),
        static fn (string $line): bool => $line !== '',
    ));

    $intro = $section['date'] !== ''
        ? 'Version '.$section['version'].', publiée le '.$section['date'].".\n\n"
        : 'Version '.$section['version'].".\n\n";

    return $intro
        ."## Ce que la version apporte\n\n"
        .implode("\n", $lines)."\n\n"
        ."<!-- Repris du ChangeLog, à relire : une annonce s'adresse à qui installe le\n"
        ."     module, pas à qui lit les commits. Retirez ce commentaire. -->\n";
}
