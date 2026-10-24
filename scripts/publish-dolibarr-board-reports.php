<?php

declare(strict_types=1);

/**
 * Relays the board meeting reports of the Dolibarr association, kept on
 * the Dolibarr wiki, to a DoliNews instance through the public API
 * (SPEC 5.2).
 *
 * The script is an editor like any other: it holds the token of a
 * contributor account belonging to the "Association Dolibarr" editor,
 * and every report it finds lands in the review queue. It publishes
 * nothing; the review does (SPEC 5.1/D6). Meant to run from cron, once
 * a day is plenty for a board that meets every two weeks.
 *
 * What is relayed: the wiki pages titled "Report board meeting
 * YYYYMMDD ...". The meeting date is read from the title, never from
 * the body: reports are started by copying the previous one, and the
 * date line inside is sometimes left as it was. Pages whose title
 * carries no full date (the oldest ones, "200904", and the blank model
 * "YYYYMMDD") are listed by --list and never submitted.
 *
 * A page is submitted only once its last edit is SETTLE_DAYS old: a
 * report is written during the meeting and completed in the days after,
 * and the review should read the finished text.
 *
 * The text is transcribed, not rewritten. The wiki is published under
 * CC BY-SA 4.0, the licence DoliNews publishes under (SPEC D15): the
 * report may be reproduced as long as its source, its authors and the
 * licence are named and the conversion is stated, which the footer of
 * every article does.
 *
 * The state file. The API identifies an announcement by its project and
 * its version (SPEC 5.2); these announcements have neither, so nothing
 * on the service side would stop a second submission of the same
 * report. The script keeps the list of pages it submitted in a JSON
 * file, local to the machine that runs it and never versioned. Before
 * submitting, it also looks for a published article of the editor
 * carrying the same title, which covers a lost state file for what the
 * review has already accepted, not for what still awaits it.
 *
 * Start point. Written once, on the first run, and never moved: without
 * it the first run would turn the whole history of the wiki into
 * pending articles. It defaults to DEFAULT_SINCE; pass --since=YYYY-MM-DD
 * on that first run to choose another one. The quota of the editor
 * (SPEC 5.3) then lets a few through at a time, and each run takes up
 * where the last one stopped. At the default values, three tokens per
 * week and five announcements in review, the reports of a year take
 * weeks to get through: raise DOLINEWS_QUOTA_BUCKET_CAPACITY and
 * DOLINEWS_QUEUE_CEILING on the instance for the first run, as for any
 * catalogue deposit.
 *
 * Archives. A report of a meeting held before the first run is news to
 * no one by the time it is published. Its entry carries
 * first_release_date = the meeting date, so that the same file serves
 * as manifest for
 *
 *   php artisan dolinews:publish-backdated <state file>
 *
 * on the instance, which publishes the reviewed archives at the date of
 * their meeting. Reports of meetings held after the first run carry no
 * such date and that command leaves them alone.
 *
 * A page edited after its submission is reported, not resubmitted: a
 * published article changes only through a revision that goes back
 * through review (SPEC 5.4), and whether an edit deserves one is a
 * judgement, not a diff.
 *
 * Usage:
 *   php scripts/publish-dolibarr-board-reports.php --list
 *   php scripts/publish-dolibarr-board-reports.php [--dry-run] [--since=YYYY-MM-DD] [--limit=N] [--state=PATH]
 *
 * Environment: DOLINEWS_API_BASE, DOLINEWS_API_TOKEN, DOLINEWS_EDITOR_SLUG,
 * DOLINEWS_EDITOR_CONTACT_EMAIL (only read when the editor is created).
 */

require_once __DIR__.'/../client/lib/dolinews-client.php';

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/** Base URL of the API, without trailing slash. */
define('API_BASE', getenv('DOLINEWS_API_BASE') ?: 'https://dolinews.com/api/v1');

/**
 * Personal token of the contributor account (Authorization: Bearer).
 * Read from the environment so that a crontab never carries it.
 */
define('API_TOKEN', getenv('DOLINEWS_API_TOKEN') ?: '');

/**
 * Slug of the editor to publish for. Empty means: take the only editor
 * the account belongs to, and refuse to guess when there are several.
 */
define('EDITOR_SLUG', getenv('DOLINEWS_EDITOR_SLUG') ?: '');

/**
 * Editor created when the account owns none yet. The name signs every
 * report. The contact address is not published; leave it empty and the
 * script stops rather than inventing one.
 */
const EDITOR_NAME = 'Association Dolibarr';
define('EDITOR_CONTACT_EMAIL', getenv('DOLINEWS_EDITOR_CONTACT_EMAIL') ?: '');
const EDITOR_WEBSITE = 'https://www.dolibarr.org';
const EDITOR_DESCRIPTION = '';

/** MediaWiki API of the Dolibarr wiki, and the base of its page URLs. */
const WIKI_API = 'https://wiki.dolibarr.org/api.php';
const WIKI_INDEX = 'https://wiki.dolibarr.org/index.php';
const WIKI_PAGE_BASE = WIKI_INDEX.'/';

/** Title prefix of the board reports on the wiki. */
const WIKI_PREFIX = 'Report board meeting';

/** Licence of the wiki, named in the footer of every article. */
const WIKI_LICENSE_NAME = 'CC BY-SA 4.0';
const WIKI_LICENSE_URL = 'https://creativecommons.org/licenses/by-sa/4.0/';

/**
 * Start point written on the first run when --since is not given: the
 * reports of the current year make the history worth having, the older
 * ones stay on the wiki.
 */
const DEFAULT_SINCE = '2026-01-01';

/**
 * Pause between two page reads, and wait before retrying a refusal. The
 * wiki answers 403 to a client reading a dozen pages in a row; it lets
 * the same request through again a little later.
 */
const WIKI_PAUSE_SECONDS = 2;
const WIKI_RETRY_SECONDS = 60;
const WIKI_RETRIES = 3;

/** Days without an edit before a report is considered finished. */
const SETTLE_DAYS = 3;

/** The reports are written in English. */
const CONTENT_LOCALE = 'en_US';

/** Default path of the state file, ignored by git. */
const STATE_DEFAULT = __DIR__.'/dolibarr-board-reports.json';

/** Limits of the API (SPEC 4.3). */
const SUMMARY_MAX = 500;
const BODY_MAX = 65535;

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------

$arguments = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $arguments, true);
$statePath = option($arguments, 'state') ?? STATE_DEFAULT;
$since = option($arguments, 'since');
$limitOption = option($arguments, 'limit');
$limit = $limitOption === null ? PHP_INT_MAX : (int) $limitOption;

if ($since !== null && ! isDate($since)) {
    fail('--since attend une date AAAA-MM-JJ, reçu : '.$since);
}

if ($limitOption !== null && $limit < 1) {
    fail('--limit attend un entier positif, reçu : '.$limitOption);
}

$pages = listReports();

if (in_array('--list', $arguments, true)) {
    printInventory($pages, readState($statePath));

    exit(0);
}

$state = readState($statePath);
$state = initialiseStartPoint($state, $since, $dryRun);

if ($since !== null && $since !== $state['_since']) {
    say('Point de départ déjà fixé au '.$state['_since'].' : --since='.$since.' est ignoré, '
        .'il ne se déplace jamais (supprimez le fichier d\'état pour repartir de zéro).');
}

dolinews_configure(API_BASE, API_TOKEN);
$profile = requireContributorProfile();
$editor = resolveEditor($profile['editors'] ?? [], $dryRun, [
    'slug' => EDITOR_SLUG,
    'name' => EDITOR_NAME,
    'contact_email' => EDITOR_CONTACT_EMAIL,
    'website' => EDITOR_WEBSITE,
    'description' => EDITOR_DESCRIPTION,
]);
say('Éditeur : '.$editor['name'].' ('.$editor['slug'].')');

reportEditedPages($pages, $state);

$settledBefore = gmdate('Y-m-d\TH:i:s\Z', time() - SETTLE_DAYS * 86400);
$submitted = 0;
$waiting = 0;
$failures = 0;

foreach ($pages as $key => $page) {
    if ($page['date'] === null || $page['date'] < $state['_since'] || isset($state[$key])) {
        continue;
    }

    if ($page['touched'] > $settledBefore) {
        say('  '.$page['title'].' : modifiée le '.substr($page['touched'], 0, 10)
            .', soumise quand elle aura '.SETTLE_DAYS.' jours sans modification.');
        $waiting++;

        continue;
    }

    if ($submitted >= $limit) {
        say('Limite de '.$limit.' soumission(s) atteinte, la suite au prochain passage.');

        break;
    }

    say('  '.$page['title']);
    $content = fetchReport($page['title']);
    $article = buildArticle($page, $content);
    $outcome = submitReport($article, $editor, $dryRun);

    if ($outcome['stop'] !== '') {
        say('Arrêt : '.$outcome['stop'].' Le prochain passage reprendra à cette page.');

        break;
    }

    if ($outcome['error'] !== '') {
        fwrite(STDERR, 'Échec pour '.$page['title'].' : '.$outcome['error']."\n");
        $failures++;

        continue;
    }

    $submitted++;

    if ($dryRun) {
        continue;
    }

    $state[$key] = array_filter([
        'wiki_title' => $page['title'],
        'wiki_revid' => $content['revid'],
        'meeting_date' => $page['date'],
        // Only an archive is moved back to its meeting date; a report
        // of a meeting held since the first run reads as fresh news,
        // which it is (see the header of this file).
        'first_release_date' => $page['date'] < $state['_first_run'] ? $page['date'] : null,
        'submitted_article_id' => $outcome['id'],
        'submitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], static fn (mixed $value): bool => $value !== null);

    // Written after every submission: a run interrupted halfway must
    // not submit again, the next day, what it already sent.
    writeState($statePath, $state);
}

say(sprintf(
    '%d compte(s) rendu(s) %s, %d en attente de stabilisation, %d échec(s).',
    $submitted,
    $dryRun ? 'à soumettre' : 'soumis',
    $waiting,
    $failures,
));

exit($failures > 0 ? 1 : 0);

// ---------------------------------------------------------------------
// Wiki
// ---------------------------------------------------------------------

/**
 * Every board report of the wiki with its last edit, keyed by a slug of
 * its title, oldest meeting first.
 *
 * One query lists them all: the generator walks the titles by prefix,
 * and the revision property returns the latest revision of each.
 *
 * @return array<string, array{title: string, date: string|null, revid: int, touched: string}>
 */
function listReports(): array
{
    $pages = [];
    $continue = [];

    do {
        $response = wikiGet([
            'action' => 'query',
            'generator' => 'allpages',
            'gapprefix' => WIKI_PREFIX,
            'gaplimit' => 'max',
            // Renamed reports leave a redirect behind, under the old title.
            'gapfilterredir' => 'nonredirects',
            'prop' => 'revisions',
            'rvprop' => 'ids|timestamp',
        ] + $continue);

        foreach ($response['query']['pages'] ?? [] as $page) {
            $title = (string) ($page['title'] ?? '');
            $revision = $page['revisions'][0] ?? null;

            if ($title === '' || ! is_array($revision)) {
                continue;
            }

            $pages[slugify($title)] = [
                'title' => $title,
                'date' => meetingDate($title),
                'revid' => (int) ($revision['revid'] ?? 0),
                'touched' => (string) ($revision['timestamp'] ?? ''),
            ];
        }

        $continue = is_array($response['continue'] ?? null) ? $response['continue'] : [];
    } while ($continue !== []);

    if ($pages === []) {
        fail('Aucune page "'.WIKI_PREFIX.'" trouvée sur '.WIKI_API.' : le wiki a changé ou ne répond pas normalement.');
    }

    uasort($pages, static fn (array $a, array $b): int => [$a['date'] ?? '', $a['title']] <=> [$b['date'] ?? '', $b['title']]);

    return $pages;
}

/**
 * The wikitext and revision of one report.
 *
 * @return array{wikitext: string, revid: int, timestamp: string}
 */
function fetchReport(string $title): array
{
    sleep(WIKI_PAUSE_SECONDS);

    $response = wikiGet([
        'action' => 'query',
        'prop' => 'revisions',
        'titles' => $title,
        'rvprop' => 'ids|timestamp|content',
        'rvslots' => 'main',
    ]);

    $revision = $response['query']['pages'][0]['revisions'][0] ?? null;
    $wikitext = $revision['slots']['main']['content'] ?? null;

    if (! is_array($revision) || ! is_string($wikitext)) {
        fail('Contenu introuvable pour la page "'.$title.'" du wiki.');
    }

    return [
        'wikitext' => $wikitext,
        'revid' => (int) ($revision['revid'] ?? 0),
        'timestamp' => (string) ($revision['timestamp'] ?? ''),
    ];
}

/**
 * One call to the MediaWiki API, JSON in the second format.
 *
 * @param  array<string, string|int>  $parameters
 * @return array<string, mixed>
 */
function wikiGet(array $parameters): array
{
    $url = WIKI_API.'?'.http_build_query($parameters + ['format' => 'json', 'formatversion' => '2']);

    for ($attempt = 0; ; $attempt++) {
        [$status, $raw] = wikiFetch($url);

        if (! in_array($status, [403, 429, 503], true) || $attempt >= WIKI_RETRIES) {
            break;
        }

        say('Le wiki a répondu '.$status.', nouvelle tentative dans '.WIKI_RETRY_SECONDS
            .' secondes ('.($attempt + 1).'/'.WIKI_RETRIES.').');
        sleep(WIKI_RETRY_SECONDS);
    }

    $decoded = json_decode($raw, true);

    if ($status !== 200 || ! is_array($decoded)) {
        fail('Le wiki a répondu '.$status.' sans JSON exploitable ('.$url.') : '.substr($raw, 0, 200));
    }

    if (isset($decoded['error'])) {
        fail('Le wiki a refusé la requête ('.$url.') : '.json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

/**
 * One GET on the wiki: status and raw body.
 *
 * @return array{0: int, 1: string}
 */
function wikiFetch(string $url): array
{
    $handle = curl_init($url);

    if ($handle === false) {
        fail('Initialisation curl impossible pour '.$url);
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        // MediaWiki asks automated clients to name themselves.
        CURLOPT_USERAGENT => 'DoliNews board report relay (+https://dolinews.com)',
    ]);

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if (! is_string($raw)) {
        fail('Le wiki n\'a pas répondu ('.$url.') : '.curl_error($handle));
    }

    return [$status, $raw];
}

/**
 * Meeting date from a title such as "Report board meeting 20260922
 * teleconf", or null when the title carries no valid full date.
 */
function meetingDate(string $title): ?string
{
    if (preg_match('/^'.preg_quote(WIKI_PREFIX, '/').' (\d{4})(\d{2})(\d{2})(?:\D|$)/', $title, $match) !== 1) {
        return null;
    }

    if (! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
        return null;
    }

    return $match[1].'-'.$match[2].'-'.$match[3];
}

// ---------------------------------------------------------------------
// Article
// ---------------------------------------------------------------------

/**
 * The announcement for one report: title, summary, Markdown body ending
 * with the attribution the licence requires.
 *
 * @param  array{title: string, date: string|null, revid: int, touched: string}  $page
 * @param  array{wikitext: string, revid: int, timestamp: string}  $content
 * @return array{title: string, summary: string, body: string}
 */
function buildArticle(array $page, array $content): array
{
    $date = (string) $page['date'];
    $spelled = gmdate('j F Y', (int) strtotime($date.' 00:00:00 UTC'));
    $url = WIKI_PAGE_BASE.wikiPath($page['title']);
    $history = WIKI_INDEX.'?title='.wikiPath($page['title']).'&action=history';

    $topics = reportTopics($content['wikitext']);
    $summary = 'Minutes of the Dolibarr Foundation board meeting of '.$spelled.'.';

    if ($topics !== []) {
        $summary .= ' Topics: '.implode('; ', $topics).'.';
    }

    $footer = "\n\n---\n\n"
        .'Transcribed from the Dolibarr wiki page ['.$page['title'].']('.$url.'), '
        .'revision '.$content['revid'].', converted from wikitext to Markdown. '
        .'Authors: see the [page history]('.$history.'). '
        .'Licensed under ['.WIKI_LICENSE_NAME.']('.WIKI_LICENSE_URL.').';

    $body = wikitextToMarkdown($content['wikitext']);

    if ($body === '') {
        $body = '_The report page is empty._';
    }

    if (strlen($body.$footer) > BODY_MAX) {
        $body = mb_strcut($body, 0, BODY_MAX - strlen($footer) - 200)
            ."\n\n_The report is longer than an article may be: read it in full on the wiki._";
    }

    return [
        'title' => 'Dolibarr Foundation board meeting of '.$spelled,
        'summary' => shorten($summary, SUMMARY_MAX),
        'body' => $body.$footer,
    ];
}

/**
 * Headings of the topics discussed, which make the summary.
 *
 * The reports put each topic under a second-level heading inside a
 * "Topics" section; the older ones use list items there instead. The
 * administrative sections (title, attendance) are not topics.
 *
 * @return list<string>
 */
function reportTopics(string $wikitext): array
{
    $topics = [];
    $inTopics = false;
    $byHeading = preg_match('/^==[^=].*==\s*$/m', $wikitext) === 1;

    foreach (preg_split('/\R/', $wikitext) ?: [] as $line) {
        if (preg_match('/^(=+)\s*(.*?)\s*\1\s*$/', $line, $match) === 1) {
            $level = strlen($match[1]);
            $text = plainText($match[2]);

            if ($level === 1) {
                $inTopics = stripos($text, 'topic') !== false;

                continue;
            }

            if ($inTopics && $text !== '') {
                $topics[] = $text;
            }

            continue;
        }

        if ($inTopics && preg_match('/^\s*\*\s*(.+)$/', $line, $match) === 1 && ! $byHeading) {
            $text = plainText($match[1]);

            if ($text !== '') {
                $topics[] = $text;
            }
        }
    }

    return array_values(array_unique($topics));
}

/**
 * Text of a wikitext fragment without its markup, for the summary.
 */
function plainText(string $fragment): string
{
    $text = inlineMarkdown($fragment);
    $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    $text = str_replace(['**', '`'], '', $text);
    $text = (string) preg_replace('/\s+/', ' ', $text);

    return trim($text, " \t:");
}

/**
 * Cut on a word boundary, ellipsis in ASCII.
 */
function shorten(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    $cut = mb_substr($text, 0, $max - 3);
    $space = mb_strrpos($cut, ' ');

    if ($space !== false && $space > $max / 2) {
        $cut = mb_substr($cut, 0, $space);
    }

    return rtrim($cut, ' ;,.').'...';
}

/**
 * Path of a wiki page in its URL, as MediaWiki writes it.
 */
function wikiPath(string $title): string
{
    return str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title)));
}

// ---------------------------------------------------------------------
// Wikitext to Markdown
// ---------------------------------------------------------------------

/**
 * Convert the wikitext of a report to Markdown.
 *
 * Only what the reports use is converted: headings, bulleted and
 * numbered lists, bold and italics, internal and external links,
 * preformatted blocks and simple tables. Templates, categories, files
 * and any remaining HTML are dropped: the service renders Markdown and
 * never free HTML (SPEC D5), and an image of the wiki is not a medium
 * the service has re-encoded (SPEC 7).
 */
function wikitextToMarkdown(string $wikitext): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $wikitext);
    $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
    $text = (string) preg_replace('/__[A-Z]+__/', '', $text);
    $text = stripTemplates($text);

    // Blocks whose content is kept as is, protected from the line rules.
    $blocks = [];
    $text = (string) preg_replace_callback(
        '/<(pre|source|syntaxhighlight)[^>]*>(.*?)<\/\1>/si',
        static function (array $match) use (&$blocks): string {
            $blocks[] = "```\n".trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5), "\n")."\n```";

            return "\x01".(count($blocks) - 1)."\x01";
        },
        $text,
    );

    $out = [];
    $inTable = false;
    // Kind of the last line written: blank, list, pre or text.
    $previous = 'blank';

    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\x01(\d+)\x01$/', trim($line), $match) === 1) {
            $out[] = '';
            $out[] = $blocks[(int) $match[1]];
            $out[] = '';
            $previous = 'blank';

            continue;
        }

        if (str_starts_with(ltrim($line), '{|')) {
            $inTable = true;

            continue;
        }

        if ($inTable) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '|}')) {
                $inTable = false;
                $out[] = '';
                $previous = 'blank';

                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '|-') || str_starts_with($trimmed, '|+')) {
                continue;
            }

            $cells = preg_split('/\s*(?:\|\||!!)\s*/', substr($trimmed, 1)) ?: [];
            $cells = array_map(static fn (string $cell): string => inlineMarkdown(tableCell($cell)), $cells);
            $cells = array_filter($cells, static fn (string $cell): bool => $cell !== '');

            if ($cells !== []) {
                $out[] = '- '.implode(' | ', $cells);
                $previous = 'list';
            }

            continue;
        }

        if (preg_match('/^(=+)\s*(.*?)\s*\1\s*$/', $line, $match) === 1) {
            // Level one is the top of the page in wikitext; the article
            // title already holds that place.
            $level = min(6, strlen($match[1]) + 1);
            $out[] = '';
            $out[] = str_repeat('#', $level).' '.inlineMarkdown($match[2]);
            $out[] = '';
            $previous = 'blank';

            continue;
        }

        if (preg_match('/^\s*([*#:;]+)\s*(.*)$/', $line, $match) === 1) {
            $markers = $match[1];
            $depth = strlen($markers) - 1;
            $bullet = str_ends_with($markers, '#') ? '1. ' : '- ';
            $content = inlineMarkdown($match[2]);

            if ($content !== '') {
                $out[] = str_repeat('  ', $depth).$bullet.$content;
                $previous = 'list';
            }

            continue;
        }

        if (preg_match('/^-{4,}\s*$/', $line) === 1) {
            $out[] = '';
            $out[] = '---';
            $out[] = '';
            $previous = 'blank';

            continue;
        }

        if (trim($line) === '') {
            $out[] = '';
            $previous = 'blank';

            continue;
        }

        // In wikitext any line that is not an item ends the list; in
        // Markdown it would be read as the continuation of the item.
        if ($previous === 'list') {
            $out[] = '';
        }

        // A leading space makes a preformatted line in wikitext. The
        // reports use it to indent prose, not to quote code: kept as
        // text, since four spaces would turn it into code in Markdown,
        // but line by line as the wiki shows it.
        $preformatted = preg_match('/^[ \t]/', $line) === 1;

        // An indented dash is how some reports write a list by hand;
        // Markdown reads it as one, nested by its indentation.
        if ($preformatted && preg_match('/^([ \t]+)[-+]\s+(.*)$/', $line, $match) === 1) {
            $depth = intdiv(max(0, strlen($match[1]) - 2), 2);
            $out[] = str_repeat('  ', $depth).'- '.inlineMarkdown($match[2]);
            $previous = 'list';

            continue;
        }

        if ($preformatted && $previous === 'pre') {
            $out[count($out) - 1] .= '\\';
        }

        $converted = escapeLineStart(inlineMarkdown(ltrim($line)));

        if ($converted === '') {
            $out[] = '';
            $previous = 'blank';

            continue;
        }

        $out[] = $converted;
        $previous = $preformatted ? 'pre' : 'text';
    }

    $markdown = implode("\n", $out);
    $markdown = (string) preg_replace("/[ \t]+\n/", "\n", $markdown);
    $markdown = (string) preg_replace("/\n{3,}/", "\n\n", $markdown);

    return trim($markdown);
}

/**
 * Drop {{...}} templates, nested ones included.
 */
function stripTemplates(string $text): string
{
    do {
        $before = $text;
        $text = (string) preg_replace('/\{\{[^{}]*\}\}/s', '', $text);
    } while ($text !== $before);

    return $text;
}

/**
 * Content of a table cell, without its attributes ("style=... | text").
 */
function tableCell(string $cell): string
{
    if (preg_match('/^[^|\[]*=[^|\[]*\|(.*)$/', $cell, $match) === 1) {
        return trim($match[1]);
    }

    return trim($cell);
}

/**
 * Inline markup: links, emphasis, line breaks, HTML leftovers.
 */
function inlineMarkdown(string $text): string
{
    // Files and categories carry nothing readable in an article.
    $text = (string) preg_replace('/\[\[\s*(?:File|Image|Fichier|Category|Catégorie)\s*:[^\]]*\]\]/iu', '', $text);

    $text = (string) preg_replace_callback(
        '/\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/',
        static function (array $match): string {
            $target = trim($match[1]);
            $label = isset($match[2]) && trim($match[2]) !== '' ? trim($match[2]) : $target;
            $anchor = '';

            if (str_contains($target, '#')) {
                [$target, $fragment] = explode('#', $target, 2);
                $anchor = '#'.str_replace(' ', '_', $fragment);
            }

            if ($target === '') {
                return $label;
            }

            return '['.$label.']('.WIKI_PAGE_BASE.wikiPath($target).$anchor.')';
        },
        $text,
    );

    $text = (string) preg_replace_callback(
        '/\[((?:https?:)?\/\/[^\s\]]+)(?:\s+([^\]]*))?\]/',
        static function (array $match): string {
            $url = str_starts_with($match[1], '//') ? 'https:'.$match[1] : $match[1];
            $label = isset($match[2]) ? trim($match[2]) : '';

            return $label === '' ? '<'.$url.'>' : '['.$label.']('.$url.')';
        },
        $text,
    );

    $text = (string) preg_replace("/'''''(.+?)'''''/", '***$1***', $text);
    $text = (string) preg_replace("/'''(.+?)'''/", '**$1**', $text);
    $text = (string) preg_replace("/''(.+?)''/", '*$1*', $text);

    $text = (string) preg_replace('/<code>(.*?)<\/code>/si', '`$1`', $text);
    $text = (string) preg_replace('/<br\s*\/?>/i', " \\\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

    // Pasted text brings non-breaking and invisible characters along:
    // spaces become plain spaces, the invisible ones go.
    $text = (string) preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{3000}]/u', ' ', $text);
    $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text);
    $text = trim($text);

    // A line holding nothing but a line break.
    return $text === '\\' ? '' : $text;
}

/**
 * Keep a prose line from reading as Markdown structure it never was:
 * a heading, a numbered item or a quote.
 */
function escapeLineStart(string $line): string
{
    if (preg_match('/^(#|>(?!>)|\d+[.)]\s)/', $line) === 1) {
        return '\\'.$line;
    }

    return $line;
}

// ---------------------------------------------------------------------
// Submission
// ---------------------------------------------------------------------

/**
 * Submit one report, after checking the editor has not already
 * published it under the same title.
 *
 * A quota refusal stops the run without an error: the page stays to be
 * submitted on the next pass (SPEC 5.3). Any other refusal is reported
 * for this page and the run goes on with the next one.
 *
 * @param  array{title: string, summary: string, body: string}  $article
 * @param  array<string, mixed>  $editor
 * @return array{id: int|null, stop: string, error: string}
 */
function submitReport(array $article, array $editor, bool $dryRun): array
{
    $existing = publishedWithTitle($article['title'], $editor);

    if ($existing !== null) {
        say('    déjà publié : #'.$existing.', rien n\'est soumis');

        return ['id' => $existing, 'stop' => '', 'error' => ''];
    }

    if ($dryRun) {
        say('    à soumettre : '.$article['title'].' ('.strlen($article['body']).' octets)');
        say('    résumé : '.$article['summary']);

        return ['id' => null, 'stop' => '', 'error' => ''];
    }

    $response = apiPostAllowingFailure('/articles', [
        'editor_id' => $editor['id'],
        'type' => 'announcement',
        'locale' => CONTENT_LOCALE,
        'title' => $article['title'],
        'summary' => $article['summary'],
        'body' => $article['body'],
        'submit' => true,
    ]);

    if ($response['status'] === 429) {
        return [
            'id' => null,
            'stop' => match ($response['error']) {
                'QUEUE_CEILING_REACHED' => 'le plafond d\'annonces simultanément en revue est atteint pour cet éditeur (SPEC 5.3).',
                'QUOTA_BUCKET_EMPTY' => 'le crédit de publication est épuisé (SPEC 5.3).',
                default => 'refus '.$response['error'].' du service.',
            },
            'error' => '',
        ];
    }

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        $detail = $response['detail'] === [] ? '' : ' '.json_encode($response['detail'], JSON_UNESCAPED_UNICODE);

        return [
            'id' => null,
            'stop' => '',
            'error' => 'POST /articles a répondu '.$response['status'].' '
                .($response['error'] !== '' ? $response['error'] : 'sans code d\'erreur').$detail,
        ];
    }

    $data = $response['data'];
    say('    soumis : #'.$data['id'].' ['.$data['status'].']');

    return ['id' => (int) $data['id'], 'stop' => '', 'error' => ''];
}

/**
 * Identifier of a published article of the editor with this exact
 * title, if any.
 *
 * @param  array<string, mixed>  $editor
 */
function publishedWithTitle(string $title, array $editor): ?int
{
    if ((int) $editor['id'] === 0) {
        return null;
    }

    $found = apiGet('/articles?'.http_build_query([
        'editor' => $editor['slug'],
        'q' => $title,
        'per_page' => 100,
    ]));

    foreach ($found as $article) {
        if (is_array($article) && ($article['title'] ?? null) === $title) {
            return (int) $article['id'];
        }
    }

    return null;
}

/**
 * Name the submitted pages edited on the wiki since their submission.
 *
 * @param  array<string, array{title: string, date: string|null, revid: int, touched: string}>  $pages
 * @param  array<string, mixed>  $state
 */
function reportEditedPages(array $pages, array $state): void
{
    foreach ($state as $key => $entry) {
        if (str_starts_with((string) $key, '_') || ! is_array($entry) || ! isset($pages[$key])) {
            continue;
        }

        if ($pages[$key]['revid'] !== (int) ($entry['wiki_revid'] ?? 0)) {
            say('  '.$pages[$key]['title'].' a été modifiée sur le wiki depuis sa soumission (article #'
                .($entry['submitted_article_id'] ?? '?').') : une révision peut être proposée '
                .'avec client/bin/publish-article.php --revise=<id> si la modification le justifie.');
        }
    }
}

// ---------------------------------------------------------------------
// State
// ---------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function readState(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    if (! is_array($decoded)) {
        fail('Fichier d\'état illisible : '.$path.'. Corrigez-le plutôt que de le supprimer : '
            .'il garde la liste des comptes rendus déjà soumis.');
    }

    return $decoded;
}

/**
 * Fix the start point on the first run, and never move it afterwards.
 *
 * @param  array<string, mixed>  $state
 * @return array<string, mixed>
 */
function initialiseStartPoint(array $state, ?string $since, bool $dryRun): array
{
    if (isset($state['_since'], $state['_first_run'])) {
        return $state;
    }

    $today = gmdate('Y-m-d');
    $state = [
        '_lisez-moi' => [
            'Fichier d\'état de scripts/publish-dolibarr-board-reports.php : la liste des',
            'comptes rendus du wiki déjà soumis à DoliNews. Ne pas le supprimer, sans quoi',
            'les comptes rendus encore en revue seraient soumis une seconde fois.',
            '',
            '_since est le point de départ, fixé au premier passage et jamais déplacé.',
            '_first_run est le jour de ce premier passage : un compte rendu de réunion',
            'antérieure porte first_release_date, et ce fichier sert de manifeste à',
            '  php artisan dolinews:publish-backdated <ce fichier>',
            'pour publier les archives relues à la date de leur réunion. Les autres',
            'entrées n\'ont pas de date à porter, la commande les ignore.',
            '',
            'Les clés commençant par un souligné sont des commentaires.',
        ],
        '_since' => $since ?? DEFAULT_SINCE,
        '_first_run' => $today,
    ] + $state;

    say('Premier passage : point de départ fixé au '.$state['_since'].'.');

    if ($dryRun) {
        say('(essai à blanc : le fichier d\'état n\'est pas écrit)');
    }

    return $state;
}

/**
 * @param  array<string, mixed>  $state
 */
function writeState(string $path, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        fail('Encodage du fichier d\'état impossible : '.json_last_error_msg());
    }

    $temporary = $path.'.tmp';

    if (file_put_contents($temporary, $json."\n") === false || ! rename($temporary, $path)) {
        fail('Écriture du fichier d\'état impossible : '.$path);
    }
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Inventory of the wiki reports and what the script does with each.
 *
 * @param  array<string, array{title: string, date: string|null, revid: int, touched: string}>  $pages
 * @param  array<string, mixed>  $state
 */
function printInventory(array $pages, array $state): void
{
    $since = is_string($state['_since'] ?? null) ? $state['_since'] : null;

    foreach ($pages as $key => $page) {
        $status = match (true) {
            $page['date'] === null => 'ignorée, pas de date complète dans le titre',
            isset($state[$key]) => 'soumise (#'.($state[$key]['submitted_article_id'] ?? '?').')',
            $since !== null && $page['date'] < $since => 'antérieure au point de départ',
            default => 'à soumettre',
        };

        say(sprintf('%-10s  %-50s  %s', $page['date'] ?? '-', $page['title'], $status));
    }

    say(count($pages).' page(s). Point de départ : '.($since ?? 'pas encore fixé (premier passage)').'.');
}

/**
 * Value of a --name=value option, or null.
 *
 * @param  list<string>  $arguments
 */
function option(array $arguments, string $name): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--'.$name.'=')) {
            return substr($argument, strlen('--'.$name.'='));
        }
    }

    return null;
}

function isDate(string $value): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) === 1
        && checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
}
