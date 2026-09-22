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
