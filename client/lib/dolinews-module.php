<?php

declare(strict_types=1);

/**
 * What a Dolibarr module repository already says about itself, and how
 * to write it back as an article file.
 *
 * Every module carries a descriptor in core/modules/modXxx.class.php
 * holding its name, its version and the Dolibarr release it needs. An
 * author asked to retype those three values into an announcement will
 * get one of them wrong sooner or later, and a wrong compatibility is
 * exactly what the feed must not carry (SPEC 4.2). So they are read
 * where they already live.
 *
 * Shared by publish-article.php (--init) and draft-from-changelog.php.
 */

/**
 * Read what the module descriptor and its French language file declare.
 *
 * Nothing here is authoritative: a descriptor left at the generator's
 * default announces a Dolibarr version nobody checked. The values are a
 * starting point for a human, which is why they land in a file to edit
 * rather than in a request.
 *
 * @return array{name: string, slug: string, version: string, dolibarr_min: ?int, summary: string}
 */
function readModuleDescriptor(string $directory): array
{
    $directory = rtrim($directory, '/');
    $found = glob($directory.'/core/modules/mod*.class.php') ?: [];

    // A repository may ship several descriptors (a module and its
    // sub-module); the shortest name is the main one.
    usort($found, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

    if ($found === []) {
        return ['name' => basename($directory), 'slug' => slugify(basename($directory)),
            'version' => '', 'dolibarr_min' => null, 'summary' => ''];
    }

    $source = (string) file_get_contents($found[0]);
    $name = preg_match('/^mod(.+)\.class\.php$/', basename($found[0]), $match) === 1
        ? $match[1]
        : basename($directory);

    $version = '';

    if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match) === 1) {
        $version = $match[1];
    }

    $min = null;

    if (preg_match('/need_dolibarr_version\s*=\s*(?:array\(|\[)\s*(\d+)/', $source, $match) === 1) {
        $min = (int) $match[1];
    }

    return [
        'name' => $name,
        'slug' => slugify($name),
        'version' => $version,
        'dolibarr_min' => $min,
        'summary' => readModuleSummary($directory, $name),
    ];
}

/**
 * The module description as its French language file states it.
 *
 * Skipped when it is the generator's placeholder ("Xxx description"):
 * shipping that as the summary of an announcement would be worse than
 * an empty field, which at least asks to be filled.
 */
function readModuleSummary(string $directory, string $name): string
{
    $files = glob($directory.'/langs/fr_FR/*.lang') ?: [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match('/^Module'.preg_quote($name, '/').'Desc\s*=\s*(.+)$/mi', $contents, $match) !== 1) {
            continue;
        }

        $summary = trim($match[1]);

        if ($summary === '' || preg_match('/^(description de |'.preg_quote($name, '/').' description$)/i', $summary) === 1) {
            return '';
        }

        return $summary;
    }

    return '';
}

/**
 * Write an article file: the header between --- lines, then the body.
 *
 * Empty values are kept as commented lines rather than dropped: the
 * author sees which fields exist and what they accept, which is the
 * whole point of generating the file instead of a command line.
 *
 * @param  array<string, string|int|null>  $meta
 * @param  array<string, string>  $hints  trailing comment per field
 */
function renderArticleFile(array $meta, string $body, array $hints = []): string
{
    $lines = ['---'];

    foreach ($meta as $key => $value) {
        $text = $value === null ? '' : (string) $value;
        $hint = isset($hints[$key]) ? '  # '.$hints[$key] : '';

        // Quote what could be read as something else: anything with a
        // colon, and version numbers, which would otherwise look like
        // numbers and lose a trailing zero on the way.
        $quoted = $text !== '' && (str_contains($text, ':') || in_array($key, ['title', 'summary', 'version'], true))
            ? '"'.str_replace('"', '\'', $text).'"'
            : $text;

        $lines[] = ($text === '' ? '# ' : '').$key.': '.$quoted.$hint;
    }

    $lines[] = '---';
    $lines[] = '';

    return implode("\n", $lines)."\n".ltrim($body);
}

/**
 * Default bound of the description. The instance decides its own; this
 * one only avoids sending eighty kilobytes to be refused.
 */
const MAX_DESCRIPTION = 8000;

/**
 * Said in an announcement, never on a sheet (SPEC D1). Caught here
 * because it is the mistake a sheet invites: the author has the
 * compatibility in mind while writing, and the sheet outlives it.
 */
const DATED_CLAIMS = [
    // Spaces and tabs, never a newline: with \s* the pattern read
    // "Dolibarr" at the end of a sentence and the "15." opening the
    // next line as one claim, and refused a sheet that said nothing of
    // the sort.
    '/\bdolibarr[ \t]*(v|version)?[ \t]*\d{1,2}\b/i',
    '/\bcompatible[ \t]+(avec[ \t]+)?(dolibarr|la[ \t]+v)/i',
    '/\bneed_dolibarr_version\b/i',
];

/**
 * What a module's own user documentation says about itself.
 *
 * Only the prose sections are taken, and the headings are demoted to
 * the level a sheet uses. What is dated - a compatibility table, a
 * changelog - is left where it belongs.
 *
 * @return array{summary: string, body: string}
 */
function readModuleDocumentation(string $directory): array
{
    $index = rtrim($directory, '/').'/docs/users/index.md';

    if (! is_file($index)) {
        return ['summary' => '', 'body' => ''];
    }

    $contents = (string) file_get_contents($index);
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    $summary = '';

    // The front matter of a documentation page carries a one-line
    // description written for a reader, which is what a sheet summary is.
    if (preg_match('/^---\n(.*?)\n---\n/s', $contents, $matches) === 1) {
        if (preg_match('/^description:\s*"?(.+?)"?\s*$/m', $matches[1], $found) === 1) {
            $summary = trim($found[1]);
        }

        $contents = (string) substr($contents, strlen($matches[0]));
    }

    $sections = [];

    // Everything up to the first heading that is not prose: what follows
    // installation or configuration belongs to the documentation.
    foreach (preg_split('/^(?=## )/m', $contents) ?: [] as $section) {
        $section = trim($section);

        if ($section === '' || str_starts_with($section, '# ')) {
            continue;
        }

        if (preg_match('/^##\s*(.+)$/m', $section, $heading) !== 1) {
            continue;
        }

        $title = mb_strtolower(trim($heading[1]));

        // Dated sections are dropped whole: what a module requires
        // today is an announcement's business, and a sheet carrying it
        // goes false on its own (SPEC D1).
        if (preg_match('/(installation|configuration|migration|faq|changelog|licence|license|prérequis|pre-requis|prerequis|compatibilit|requirements)/u', $title) === 1) {
            continue;
        }

        $sections[] = $section;
    }

    $body = trim(implode("\n\n", $sections));

    // And the stray line that says it inside a section kept for the
    // rest of its content.
    $kept = [];
    $dropped = 0;

    foreach (explode("\n", $body) as $line) {
        $dated = false;

        foreach (DATED_CLAIMS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $dated = true;
                break;
            }
        }

        if ($dated) {
            $dropped++;

            continue;
        }

        $kept[] = $line;
    }

    if ($dropped > 0) {
        say($dropped.' ligne(s) de la documentation écartée(s) : elles nomment une version '
            .'de Dolibarr, ce qu\'une fiche ne porte jamais. Cela appartient à l\'annonce.');
    }

    $body = trim(implode("\n", $kept));

    $body = cleanDocumentationBody($body);

    if (mb_strlen($body) > MAX_DESCRIPTION) {
        $body = mb_substr($body, 0, MAX_DESCRIPTION);
        $cut = mb_strrpos($body, "\n\n");
        $body = $cut === false ? $body : mb_substr($body, 0, $cut);
    }

    return ['summary' => $summary, 'body' => trim($body)];
}

/**
 * What a documentation body carries that a sheet must not.
 *
 * Images go whole: a screenshot hosted by the documentation site is not
 * deposited here, and a sheet only ever shows media the service serves
 * itself (SPEC 5.2/7). They are removed BEFORE the links, stripping the
 * link part alone having left the "!" of the Markdown syntax standing
 * in the middle of a sentence.
 *
 * Relative links are reduced to their text, for the same reason: they
 * point at a documentation tree this page is not part of. Absolute ones
 * are kept and come out nofollow ugc like every outgoing link (D8).
 */
function cleanDocumentationBody(string $body): string
{
    $body = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)\s*/', '', $body);

    // A Dolistore convention trailing its images, meaningless here.
    $body = str_replace('{imgmd}', '', $body);

    $body = (string) preg_replace('/\[([^\]]*)\]\((?!https?:)[^)]*\)/', '$1', $body);

    // Blank lines left behind by what was removed.
    return trim((string) preg_replace('/\n{3,}/', "\n\n", $body));
}
