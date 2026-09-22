<?php

declare(strict_types=1);

/**
 * Submits the CAP-REL catalogue to a DoliNews instance: one project
 * sheet and one announcement per module, through the public API only
 * (SPEC 5.2).
 *
 * Two steps, on purpose.
 *
 *   php scripts/publish-caprel-catalog.php --scan
 *       Walks the local clones, reads the first tag and its date, the
 *       module descriptor and its language file, and writes a manifest.
 *       Touches no network and no instance.
 *
 *   php scripts/publish-caprel-catalog.php [--dry-run]
 *       Reads the manifest back and submits the entries marked
 *       "publish": true, recording the identifier each one got.
 *
 * Then, ON THE INSTANCE, the third step:
 *
 *   php artisan dolinews:publish-backdated <manifest>
 *       Publishes those articles under their first-version date. It
 *       cannot happen here: a token grants the right to submit, never to
 *       publish (SPEC 5.2), so the manifest is copied to the instance and
 *       the super admin's derogation does the rest. Re-running that step
 *       also corrects the date of an entry already published under
 *       another one, so a date fixed in the manifest after the fact is
 *       never out of reach.
 *
 * The manifest is where a human intervenes, and that is the point. A
 * scan reports what a repository says about itself, which is often
 * wrong: a module re-imported into a fresh repository carries a first
 * commit more recent than its first tag, and a descriptor left at the
 * generator's default announces a Dolibarr version nobody checked. Every
 * entry ships with "publish": false, so nothing leaves by accident.
 *
 * ON THE DATE. first_release_date is what the third step publishes the
 * article under (SPEC 5.1), so that a catalogue reads as the history it
 * is rather than as a burst of same-day announcements. An entry without
 * that date is never published by that command: it would land at the top
 * of the feed, which is the one outcome to avoid. The body should state
 * the date too -- the badge says an announcement is back-dated, the text
 * says what it announces.
 *
 * ON THE VOLUME. The bootstrap phase is capped at a ceiling set before
 * opening, and it never reopens; it also closes on the first submission
 * by a third-party account (SPEC 5.1). An editor may only have a handful
 * of articles pending review at once (SPEC 5.3). This script stops
 * cleanly on the queue ceiling and says what is left.
 *
 * Re-running is safe: the manifest records the identifier of each
 * article it submitted, and entries already submitted are skipped.
 *
 * Usage:
 *   php scripts/publish-caprel-catalog.php --scan [--manifest=PATH]
 *   php scripts/publish-caprel-catalog.php [--dry-run] [--manifest=PATH]
 */

require_once __DIR__.'/lib/dolinews-client.php';

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/**
 * Base URL of the API, without trailing slash. DOLINEWS_API_BASE
 * overrides it, to aim a staging instance without editing the file.
 */
define('API_BASE', getenv('DOLINEWS_API_BASE') ?: 'https://dolinews.com/api/v1');

/**
 * Personal token of the contributor account (Authorization: Bearer).
 *
 * Read from DOLINEWS_API_TOKEN when the variable is set: the token of a
 * real account then never has to be written into a versioned file, and
 * never ends up in a diff by accident.
 */
define('API_TOKEN', getenv('DOLINEWS_API_TOKEN') ?: '');

/**
 * Slug of the editor to publish for. Empty means: take the only editor
 * the account belongs to, and refuse to guess when there are several.
 */
const EDITOR_SLUG = '';

/**
 * Editor created when the account owns none yet. The contact address is
 * not published, the review team writes to it; leave it empty and the
 * script stops rather than inventing one.
 */
const EDITOR_NAME = 'CAP-REL';
const EDITOR_CONTACT_EMAIL = '';
const EDITOR_WEBSITE = 'https://www.cap-rel.fr';
const EDITOR_DESCRIPTION = '';

/** Where the manifest is written and read back. */
const MANIFEST_DEFAULT = __DIR__.'/caprel-catalog.json';

/** Directories holding the clones, walked one level deep. */
const SCAN_ROOTS = [
    '/home/groups/devs/code/modules-dolibarr',
    '/home/groups/devs/code',
];

/**
 * A clone belongs to the catalogue when its origin remote matches this,
 * once normalised to its https form. Nothing else identifies it: a
 * directory name proves nothing, and the roots above also hold
 * third-party modules and upstream Dolibarr.
 */
const CAPREL_REMOTE = '/cap-rel/';

/**
 * Directories never proposed even when their remote matches: internal
 * tooling, shared libraries and application bases are not products, and
 * a feed of them would say nothing to a reader.
 */
const SCAN_EXCLUDE = [
    'stubs', 'rector', 'img', 'archives', 'migrated', 'modeles-dolibarr',
    'demomodules', 'capnewmodule', 'schemaCapRel', 'laravel-ops',
    'webservice-base', 'webservice-base3', 'webservice-saas-base3',
    'smartcommon', 'smartboot', 'smartc', 'smartauth', '00-modinfo',
    '00-dolicompat', 'dolinews',
];

/** Locale of the submitted articles and sheets. */
const CONTENT_LOCALE = 'fr_FR';

// ---------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------

$arguments = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $arguments, true);
$manifestPath = manifestPath($arguments);

if (in_array('--scan', $arguments, true)) {
    scanCatalogue($manifestPath);

    exit(0);
}

submitCatalogue($manifestPath, $dryRun);

exit(0);

// ---------------------------------------------------------------------
// Step one: the scan
// ---------------------------------------------------------------------

/**
 * Walk the roots, keep the CAP-REL clones, and write the manifest.
 *
 * An existing manifest is merged rather than overwritten: the whole
 * value of the file is the human editing it carries, and a second scan
 * after a new module appeared must not discard it.
 */
function scanCatalogue(string $manifestPath): void
{
    $previous = is_readable($manifestPath) ? readManifest($manifestPath) : [];
    $byRepo = [];

    foreach (SCAN_ROOTS as $root) {
        if (! is_dir($root)) {
            say('Racine ignorée, introuvable : '.$root);

            continue;
        }

        foreach (directoriesOf($root) as $path) {
            $entry = inspectRepository($path);

            if ($entry === null) {
                continue;
            }

            // Several checkouts of one repository are routine here
            // (a symlinked lowercase alias, a kept "-v1" copy). The
            // remote identifies the project, the path does not: keep
            // the shortest name, which is the canonical checkout.
            $key = $entry['repo_url'];

            if (isset($byRepo[$key]) && strlen($byRepo[$key]['name']) <= strlen($entry['name'])) {
                continue;
            }

            $byRepo[$key] = $entry;
        }
    }

    $entries = array_values($byRepo);

    // Oldest first, and everything undated last: the manifest is read to
    // pick what goes out first, and a project whose date nobody knows is
    // precisely the one that needs a human before anything else.
    usort($entries, static function (array $a, array $b): int {
        $left = (string) ($a['first_release_date'] ?? $a['first_commit_date'] ?? '');
        $right = (string) ($b['first_release_date'] ?? $b['first_commit_date'] ?? '');

        if (($left === '') !== ($right === '')) {
            return $left === '' ? 1 : -1;
        }

        return strcmp($left, $right);
    });

    $merged = [];
    $kept = 0;

    foreach ($entries as $entry) {
        $existing = $previous[$entry['slug']] ?? null;

        if ($existing !== null) {
            // What the operator wrote wins over what the scan just read:
            // only the facts refreshed from git are overwritten.
            $merged[$entry['slug']] = $existing + $entry;
            $merged[$entry['slug']]['first_version'] = $entry['first_version'];
            $merged[$entry['slug']]['first_release_date'] = $entry['first_release_date'];
            $merged[$entry['slug']]['first_commit_date'] = $entry['first_commit_date'];
            $merged[$entry['slug']]['current_version'] = $entry['current_version'];
            $kept++;

            continue;
        }

        $merged[$entry['slug']] = $entry;
    }

    writeManifest($manifestPath, $merged);

    say(count($merged).' projets dans le manifeste ('.$kept.' déjà présents, conservés tels quels) : '
        .$manifestPath);
    say('');
    say('Relisez-le avant toute soumission. Chaque entrée porte "publish": false ;');
    say('passez à true celles à soumettre, et corrigez résumé, description et dates :');
    say('un dépôt réimporté annonce un premier commit plus récent que son premier tag,');
    say('et un descripteur laissé au défaut du générateur annonce une version Dolibarr');
    say('que personne n\'a vérifiée (compat_status reste "declared", SPEC 4.3).');
    say('');
    say('first_release_date est la date sous laquelle l\'article sera publié : une');
    say('entrée sans elle ne sera pas publiée par dolinews:publish-backdated.');
    say('');
    say('Rappel : la phase d\'amorçage se ferme d\'office à la première soumission');
    say('d\'un compte tiers, et elle ne se rouvre jamais (SPEC 5.1).');
}

/**
 * Read one clone, or null when it is not a CAP-REL project.
 *
 * @return array<string, mixed>|null
 */
function inspectRepository(string $path): ?array
{
    $name = basename($path);

    if (in_array($name, SCAN_EXCLUDE, true) || ! is_dir($path.'/.git')) {
        return null;
    }

    $remote = git($path, ['remote', 'get-url', 'origin']);

    if ($remote === null || $remote === '') {
        return null;
    }

    // Matched on the normalised form so one test covers both remote
    // shapes: ssh separates the namespace with a colon, https with a
    // slash.
    $remote = normaliseRemote($remote);

    if (! str_contains($remote, CAPREL_REMOTE)) {
        return null;
    }

    $firstTag = firstTag($path);
    $descriptor = readDescriptor($path);

    $firstDate = $firstTag['date'] ?? null;
    $commitDate = git($path, ['log', '--reverse', '--format=%ad', '--date=short']);
    $commitDate = $commitDate === null ? null : strtok($commitDate, "\n");

    $displayName = $descriptor['name'] ?? $name;

    return [
        // Nothing is submitted until a human says so. The ceiling of the
        // bootstrap phase is ten, the catalogue is ten times that.
        'publish' => false,
        'slug' => slugify($displayName),
        'name' => $displayName,
        'repo_path' => $path,
        'repo_url' => $remote,
        'first_version' => $firstTag['tag'] ?? null,
        'first_release_date' => $firstDate,
        'first_commit_date' => $commitDate === false ? null : $commitDate,
        'current_version' => $descriptor['version'] ?? null,
        'summary' => $descriptor['summary'] ?? '',
        'description' => $descriptor['description'] ?? '',
        'license' => $descriptor['license'] ?? 'GPL-3.0-or-later',
        'dolibarr_min' => $descriptor['dolibarr_min'] ?? null,
        // announcement rather than release: the module came out years
        // ago, and dressing an old version as a fresh release in a feed
        // of dated events would misread it. focus stays null on an
        // announcement (SPEC 4.3).
        'type' => 'announcement',
        'article_title' => '',
        'article_summary' => '',
        'article_body' => '',
        'submitted_article_id' => null,
    ];
}

/**
 * Oldest tag of a repository and its creation date.
 *
 * @return array{tag: string, date: string}|null
 */
function firstTag(string $path): ?array
{
    $output = git($path, [
        'for-each-ref',
        '--sort=creatordate',
        '--format=%(refname:short)|%(creatordate:short)',
        'refs/tags/*',
    ]);

    if ($output === null || $output === '') {
        return null;
    }

    $first = strtok($output, "\n");

    if ($first === false || ! str_contains($first, '|')) {
        return null;
    }

    [$tag, $date] = explode('|', $first, 2);

    return ['tag' => $tag, 'date' => $date];
}

/**
 * What a Dolibarr module descriptor says about itself: declared name,
 * current version, minimum Dolibarr version, and the description once
 * resolved through the French language file.
 *
 * @return array<string, mixed>
 */
function readDescriptor(string $path): array
{
    $candidates = glob($path.'/core/modules/mod*.class.php') ?: [];

    if ($candidates === []) {
        return [];
    }

    $source = (string) file_get_contents($candidates[0]);
    $found = [];

    if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) === 1) {
        $found['version'] = $m[1];
    }

    if (preg_match('/\$this->need_dolibarr_version\s*=\s*array\s*\(\s*(\d+)/', $source, $m) === 1) {
        $found['dolibarr_min'] = (int) $m[1];
    }

    $moduleName = preg_replace('/^mod/i', '', basename($candidates[0], '.class.php'));
    $keys = [];

    if (preg_match('/\$this->description\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) === 1) {
        $keys[] = $m[1];
    }

    // The descriptor often points at a key that does not exist, while
    // the language file carries the Module<Name>Desc convention. Both
    // are tried, the first that resolves wins.
    $keys[] = 'Module'.$moduleName.'Desc';
    $description = resolveLangKeys($path, $keys);
    $declaredName = resolveLangKeys($path, ['Module'.$moduleName.'Name']);

    if ($declaredName !== null) {
        $found['name'] = $declaredName;
    }

    if ($description !== null) {
        $found['description'] = $description;
        // A sheet summary is capped at 255 characters (SPEC 4.2).
        $found['summary'] = mb_substr($description, 0, 240);
    }

    return $found;
}

/**
 * Value of the first key that resolves in the French language files.
 *
 * @param  list<string>  $keys
 */
function resolveLangKeys(string $path, array $keys): ?string
{
    $files = glob($path.'/langs/fr_FR/*.lang') ?: [];

    foreach ($keys as $key) {
        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.+)$/', $line, $m) === 1) {
                    $value = trim($m[1]);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------
// Step two: the submission
// ---------------------------------------------------------------------

/**
 * Submit the entries the manifest marks for publication.
 */
function submitCatalogue(string $manifestPath, bool $dryRun): void
{
    if (! is_readable($manifestPath)) {
        fail('Manifeste introuvable : '.$manifestPath."\n"
            .'Lancez d\'abord : php '.basename(__FILE__).' --scan');
    }

    $manifest = readManifest($manifestPath);
    $selected = array_filter($manifest, static fn (array $e): bool => ($e['publish'] ?? false) === true);

    if ($selected === []) {
        fail('Aucune entrée du manifeste ne porte "publish": true. '
            .'Rien n\'est soumis tant que personne n\'a choisi.');
    }

    foreach ($selected as $slug => $entry) {
        assertComplete($slug, $entry);
    }

    dolinews_configure(API_BASE, API_TOKEN);

    say('Cible : '.API_BASE.($dryRun ? ' (simulation)' : ''));
    say(count($selected).' projets retenus sur '.count($manifest).' inventoriés.');

    $profile = requireContributorProfile();

    $editor = resolveEditor($profile['editors'] ?? [], $dryRun, [
        'slug' => EDITOR_SLUG,
        'name' => EDITOR_NAME,
        'contact_email' => EDITOR_CONTACT_EMAIL,
        'website' => EDITOR_WEBSITE,
        'description' => EDITOR_DESCRIPTION,
    ]);

    say('Éditeur : '.$editor['name'].' (#'.$editor['id'].', rôle '.$editor['role'].')');
    say('');

    $submitted = 0;

    foreach ($selected as $slug => $entry) {
        sleep(1); // throttle
        if (($entry['submitted_article_id'] ?? null) !== null) {
            say('· '.$slug.' : déjà soumis (article #'.$entry['submitted_article_id'].'), ignoré.');

            continue;
        }

        $project = ensureProject($entry, $editor, $dryRun);
        $outcome = submitArticle($entry, $project, $editor, $dryRun);

        if ($outcome['stop'] !== '') {
            say('');
            say('Arrêt : '.$outcome['stop']);
            say($submitted.' articles soumis pendant cette exécution. Les autres restent');
            say('à soumettre : relancez le script quand la file se sera vidée, il reprend');
            say('où il s\'est arrêté.');

            break;
        }

        if ($outcome['id'] !== null) {
            $manifest[$slug]['submitted_article_id'] = $outcome['id'];
            writeManifest($manifestPath, $manifest);
        }

        $submitted++;
    }

    say('');
    say('Terminé : '.$submitted.' articles soumis. Un jeton donne le droit de soumettre,');
    say('jamais celui de publier : ils attendent la revue dans le back-office.');

    if ($submitted > 0 && ! $dryRun) {
        say('');
        say('Pour les publier à leur date de première version, copiez ce manifeste sur');
        say('l\'instance et lancez :');
        say('    php artisan dolinews:publish-backdated '.basename($manifestPath));
        say('');
        say('La commande se relance sans risque : un article déjà à sa date est laissé');
        say('tel quel, et un article publié sous une autre date est corrigé. C\'est elle,');
        say('jamais ce script, qui porte les dates : un jeton d\'API ne publie pas et ne');
        say('redate pas.');
    }
}

/**
 * Refuse an entry whose text is missing before a single call goes out.
 *
 * Checked up front for the whole selection rather than entry by entry:
 * stopping at the seventh project of ten, six sheets already created,
 * because its summary was empty is a state nobody wants to sort out.
 *
 * @param  array<string, mixed>  $entry
 */
function assertComplete(string $slug, array $entry): void
{
    foreach (['name', 'summary', 'article_title', 'article_summary', 'article_body'] as $field) {
        if (trim((string) ($entry[$field] ?? '')) === '') {
            fail('Entrée "'.$slug.'" : le champ "'.$field.'" est vide. '
                .'Le manifeste est le brouillon, complétez-le avant de soumettre.');
        }
    }

    if (! in_array($entry['type'] ?? '', ['release', 'announcement'], true)) {
        fail('Entrée "'.$slug.'" : "type" vaut "'.($entry['type'] ?? '').'", '
            .'attendu "release" ou "announcement".');
    }
}

/**
 * The project sheet, reused when it already exists.
 *
 * It carries NO Dolibarr compatibility on purpose (SPEC 4.2): anything
 * dated lives in the feed, on the article.
 *
 * @param  array<string, mixed>  $entry
 * @param  array<string, mixed>  $editor
 * @return array<string, mixed>
 */
function ensureProject(array $entry, array $editor, bool $dryRun): array
{
    $slug = (string) $entry['slug'];
    $project = $dryRun ? null : apiGetOrNull('/projects/'.$slug);

    if ($project !== null) {
        say('· '.$slug.' : fiche déjà présente (#'.$project['id'].'), réutilisée.');
    } elseif ($dryRun) {
        say('· '.$slug.' : fiche à créer.');
        $project = ['id' => 0, 'slug' => $slug, 'links' => []];
    } else {
        $project = apiPost('/projects', [
            'editor_id' => $editor['id'],
            'name' => $entry['name'],
            'summary' => $entry['summary'],
            'description' => $entry['description'] !== '' ? $entry['description'] : null,
            'license' => $entry['license'],
        ]);
        say('· '.$slug.' : fiche créée (#'.$project['id'].').');
    }

    $repoUrl = (string) ($entry['repo_url'] ?? '');
    $existing = array_column($project['links'] ?? [], 'url');

    if ($repoUrl !== '' && ! in_array($repoUrl, $existing, true)) {
        if ($dryRun) {
            say('    lien repo à ajouter : '.$repoUrl);
        } else {
            apiPost('/projects/'.$project['slug'].'/links', [
                'type' => 'repo',
                'url' => $repoUrl,
                'label' => 'Dépôt git',
            ]);
            say('    lien repo ajouté : '.$repoUrl);
        }
    }

    return $project;
}

/**
 * Submit the announcement, and report a quota refusal rather than dying
 * on it: the run has already created sheets, and the operator needs to
 * know exactly where it stopped (SPEC 5.3).
 *
 * @param  array<string, mixed>  $entry
 * @param  array<string, mixed>  $project
 * @param  array<string, mixed>  $editor
 * @return array{id: int|null, stop: string}
 */
function submitArticle(array $entry, array $project, array $editor, bool $dryRun): array
{
    $payload = [
        'editor_id' => $editor['id'],
        'project_id' => $project['id'],
        'type' => $entry['type'],
        'version' => $entry['first_version'],
        'locale' => CONTENT_LOCALE,
        'title' => $entry['article_title'],
        'summary' => $entry['article_summary'],
        'body' => $entry['article_body'],
        'maturity' => 'stable',
        // The descriptor's minimum is what its author declared, not
        // what anyone ran: declared, never tested (SPEC 4.3).
        'compat_status' => 'declared',
        'dolibarr_min' => $entry['dolibarr_min'],
        'submit' => true,
    ];

    if ($entry['type'] === 'release') {
        $payload['focus'] = $entry['focus'] ?? 'feature_major';
    }

    if ($dryRun) {
        say('    article à soumettre : '.$entry['article_title']
            .' ('.strlen((string) $entry['article_body']).' octets)');

        return ['id' => null, 'stop' => ''];
    }

    $response = apiPostAllowingFailure('/articles', array_filter(
        $payload,
        static fn (mixed $value): bool => $value !== null,
    ));

    if ($response['status'] === 429) {
        return [
            'id' => null,
            'stop' => $response['error'] === 'QUEUE_CEILING_REACHED'
                ? 'le plafond d\'articles simultanément en revue est atteint pour cet éditeur (SPEC 5.3).'
                : 'le crédit de publication est épuisé (SPEC 5.3).',
        ];
    }

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        fail('POST /articles a répondu '.$response['status'].' pour "'.$entry['slug'].'" : '
            .($response['error'] !== '' ? $response['error'] : 'réponse inattendue'));
    }

    $article = $response['data'];
    say('    article soumis : #'.$article['id'].' ['.$article['status'].']');

    return ['id' => (int) $article['id'], 'stop' => ''];
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Path of the manifest, overridable with --manifest=PATH.
 *
 * @param  list<string>  $arguments
 */
function manifestPath(array $arguments): string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--manifest=')) {
            return substr($argument, strlen('--manifest='));
        }
    }

    return MANIFEST_DEFAULT;
}

/**
 * @return array<string, array<string, mixed>>
 */
function readManifest(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        fail('Manifeste illisible ou mal formé : '.$path);
    }

    return $decoded;
}

/**
 * @param  array<string, array<string, mixed>>  $manifest
 */
function writeManifest(string $path, array $manifest): void
{
    $json = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );

    if ($json === false) {
        fail('Encodage JSON du manifeste impossible : '.json_last_error_msg());
    }

    if (file_put_contents($path, $json."\n") === false) {
        fail('Écriture du manifeste impossible : '.$path);
    }
}

/**
 * Immediate subdirectories of a root, symlinks left out: a lowercase
 * alias pointing at a sibling would be inspected twice.
 *
 * @return list<string>
 */
function directoriesOf(string $root): array
{
    $paths = [];

    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $root.'/'.$name;

        if (is_dir($path) && ! is_link($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

/**
 * Public https form of a git remote: an ssh remote is an address for
 * whoever holds the key, and a sheet link must open in a browser.
 * Anything unrecognised is returned as-is and the operator sees it in
 * the manifest.
 */
function normaliseRemote(string $remote): string
{
    $remote = preg_replace('/\.git$/', '', trim($remote)) ?? $remote;

    if (preg_match('#^[^@]+@([^:]+):(.+)$#', $remote, $m) === 1) {
        return 'https://'.$m[1].'/'.$m[2];
    }

    return $remote;
}

/**
 * Run one git command in a clone, or null when it fails.
 *
 * @param  list<string>  $arguments
 */
function git(string $path, array $arguments): ?string
{
    $command = 'git -C '.escapeshellarg($path);

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $status = 0;
    exec($command.' 2>/dev/null', $output, $status);

    if ($status !== 0) {
        return null;
    }

    return trim(implode("\n", $output));
}
