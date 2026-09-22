<?php

declare(strict_types=1);

/**
 * Submits ONE article to a DoliNews instance from a Markdown file.
 *
 * The other scripts of this directory carry their content inline, which
 * suits a one-off catalogue import and nothing else. A module author
 * writes a release note in a file, next to the code it describes, and
 * versions it there. This script takes that file and nothing else:
 *
 *   php scripts/publish-article.php annonce.md
 *
 * The file carries its own metadata in a header delimited by ---, the
 * convention of static site generators, so the file stays readable as
 * is and needs no companion. Everything after the header is the body,
 * in Markdown.
 *
 *   ---
 *   title: "MonModule 2.1 : import des factures fournisseur"
 *   summary: "Une phrase ou deux, 500 caracteres au plus."
 *   type: release
 *   version: "2.1.0"
 *   focus: feature_major
 *   project: monmodule
 *   dolibarr_min: 18
 *   ---
 *
 *   ## Ce que la version apporte
 *
 *   Le texte de l'annonce.
 *
 * ON WHAT A TOKEN GRANTS. It grants the right to SUBMIT, never the
 * right to publish (SPEC 5.2). What this script sends lands in the
 * review queue, where the team reads it; publication follows the review
 * and nothing else. A script that announced "published" would lie.
 *
 * ON IMAGES. A relative path in the Markdown is resolved next to the
 * file, deposited through POST /media, and replaced by the URL the
 * service serves it under. Only media served by the service illustrate
 * an article (SPEC 5.2/7): an image left pointing at a developer's disk
 * would simply be missing from the feed.
 *
 * ON TRANSLATIONS. A file named annonce.en_US.md next to annonce.md is
 * submitted as the English version of the same announcement, linked to
 * it. A translation is an article in its own right and goes through the
 * review like any other (SPEC 4.3, D14).
 *
 * Usage:
 *   php scripts/publish-article.php <fichier.md> [--dry-run] [--draft]
 *                                   [--editor=slug] [--no-translations]
 *   php scripts/publish-article.php <fichier.md> --check
 *   php scripts/publish-article.php <fichier.md> --revise=<id> --motive="..."
 *   php scripts/publish-article.php --init [fichier.md] [--module=<chemin>]
 *   php scripts/publish-article.php --help
 *
 * Environment:
 *   DOLINEWS_API_TOKEN     personal token of the contributor account (required)
 *   DOLINEWS_API_BASE      API root, to aim another instance
 *   DOLINEWS_EDITOR_EMAIL  contact address, only used to create a first editor
 *   DOLINEWS_EDITOR_NAME   name of that editor
 */

require_once __DIR__.'/lib/dolinews-client.php';
require_once __DIR__.'/lib/dolinews-module.php';

/** Base URL of the API, without trailing slash. */
define('API_BASE', getenv('DOLINEWS_API_BASE') ?: 'https://dolinews.com/api/v1');

/** Personal token of the contributor account (Authorization: Bearer). */
define('API_TOKEN', getenv('DOLINEWS_API_TOKEN') ?: '');

/**
 * Editor created when the account owns none yet: one never publishes
 * under one's own name, always under an editor. The contact address is
 * not published, the review team writes to it.
 */
define('EDITOR_NAME', getenv('DOLINEWS_EDITOR_NAME') ?: '');
define('EDITOR_CONTACT_EMAIL', getenv('DOLINEWS_EDITOR_EMAIL') ?: '');

/** Locale of the article when the header does not say. */
const DEFAULT_LOCALE = 'fr_FR';

/** Values the API accepts, checked here to fail before the network. */
const TYPES = ['release', 'announcement'];
const FOCUSES = ['doc', 'cleanup', 'feature_minor', 'feature_major',
    'bugfix_minor', 'bugfix_major', 'security', 'compat', 'eol'];
const MATURITIES = ['alpha', 'beta', 'rc', 'stable', 'deprecated'];
const COMPAT_STATUSES = ['declared', 'tested', 'experimental'];

/** Limits of POST /articles, mirrored to report them in place. */
const MAX_TITLE = 255;
const MAX_SUMMARY = 500;
const MAX_BODY = 65535;
const MAX_VERSION = 32;
const MAX_MOTIVE = 255;
const MAX_MEDIA = 30;

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

    [$meta, $body] = readArticleFile($path);

    say('Fichier : '.$path);
    say('Titre   : '.$meta['title']);

    // Everything above needs no network and no token, which is what
    // --check exists for: a file can be validated in a pipeline, or by
    // someone who has not opened an account yet.
    if ($options['check']) {
        return reportCheck($path, $meta, $body, $options);
    }

    // Checked here rather than left to the client: this script takes
    // its token from the environment, and the client's message sends
    // the reader editing a constant that does not exist here.
    if (API_TOKEN === '') {
        fail('Jeton absent. Posez DOLINEWS_API_TOKEN dans votre environnement : '
            .'export DOLINEWS_API_TOKEN="1|abc..." Le jeton se crée depuis votre compte '
            .'contributeur, rubrique Jetons d\'API. Sans jeton, --check vérifie le fichier.');
    }

    dolinews_configure(API_BASE, API_TOKEN);

    $profile = requireContributorProfile();
    $editor = resolveEditor($profile['editors'] ?? [], $options['dryRun'], [
        'slug' => $options['editor'] !== '' ? $options['editor'] : ($meta['editor'] ?? ''),
        'name' => EDITOR_NAME !== '' ? EDITOR_NAME : ($profile['name'] ?? 'Editeur'),
        'contact_email' => EDITOR_CONTACT_EMAIL,
    ]);

    say('Éditeur : '.$editor['name'].' ('.$editor['slug'].')');

    if ($options['revise'] > 0) {
        return submitRevision($options, $meta, $body);
    }

    $project = resolveProject($meta['project'] ?? '', $editor);

    [$body, $mediaIds, $images] = depositImages($body, dirname($path), $editor, $options['dryRun']);

    $payload = array_filter([
        'editor_id' => $editor['id'],
        'project_id' => $project['id'] ?? null,
        'type' => $meta['type'],
        'focus' => $meta['focus'] ?? null,
        'title' => $meta['title'],
        'version' => $meta['version'] ?? null,
        'summary' => $meta['summary'],
        'body' => $body,
        'locale' => $meta['locale'],
        'dolibarr_min' => $meta['dolibarr_min'] ?? null,
        'dolibarr_max' => $meta['dolibarr_max'] ?? null,
        'maturity' => $meta['maturity'] ?? null,
        'compat_status' => $meta['compat_status'] ?? null,
        'media_ids' => $mediaIds === [] ? null : $mediaIds,
        'submit' => ! $options['draft'],
    ], static fn (mixed $value): bool => $value !== null);

    $translations = $options['translations'] ? siblingTranslations($path, $meta['locale']) : [];

    if ($options['dryRun']) {
        say('');
        say('--dry-run : rien n\'a été envoyé. Corps de '.strlen($body).' octets.');

        foreach ($translations as $locale => $file) {
            say('    traduction '.$locale.' à soumettre : '.basename($file));
        }

        return 0;
    }

    $response = apiPostAllowingFailure('/articles', $payload);

    if ($response['status'] === 429) {
        failOnQuota($response['error']);
    }

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        fail('POST /articles a répondu '.$response['status']
            .($response['error'] !== '' ? ' : '.$response['error'] : '').'.');
    }

    $article = $response['data'];

    say('');
    say('Article #'.$article['id'].' : '.$article['title']);
    say('Statut  : '.$article['status']);

    foreach ($translations as $locale => $file) {
        submitTranslation((int) $article['id'], $file, $locale, $images, $options);
    }

    if ($options['draft']) {
        say('Brouillon enregistré, non soumis. Relancez sans --draft pour le soumettre.');

        return 0;
    }

    say('L\'article est en file de revue. Un jeton donne le droit de soumettre, '
        .'jamais celui de publier : la publication suit la revue.');
    say('Aucun délai n\'est promis ; le fil de revue vous répondra depuis votre compte.');

    return 0;
}

/**
 * @param  list<string>  $args
 * @return array{file: string, dryRun: bool, draft: bool, editor: string, help: bool,
 *               check: bool, init: bool, module: string, revise: int, motive: string,
 *               translations: bool}
 */
function parseArguments(array $args): array
{
    $parsed = [
        'file' => '', 'dryRun' => false, 'draft' => false, 'editor' => '', 'help' => false,
        'check' => false, 'init' => false, 'module' => '.', 'revise' => 0, 'motive' => '',
        'translations' => true,
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $parsed['help'] = true;
        } elseif ($arg === '--dry-run') {
            $parsed['dryRun'] = true;
        } elseif ($arg === '--draft') {
            $parsed['draft'] = true;
        } elseif ($arg === '--check') {
            $parsed['check'] = true;
        } elseif ($arg === '--init') {
            $parsed['init'] = true;
        } elseif ($arg === '--no-translations') {
            $parsed['translations'] = false;
        } elseif (str_starts_with($arg, '--editor=')) {
            $parsed['editor'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--module=')) {
            $parsed['module'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--motive=')) {
            $parsed['motive'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--revise=')) {
            $value = substr($arg, 9);

            if (preg_match('/^\d+$/', $value) !== 1) {
                fail('--revise attend le numéro de l\'article à corriger : --revise=42.');
            }

            $parsed['revise'] = (int) $value;
        } elseif (str_starts_with($arg, '-')) {
            fail('Option inconnue : '.$arg.'. Lancez --help.');
        } elseif ($parsed['file'] === '') {
            $parsed['file'] = $arg;
        } else {
            fail('Un seul fichier à la fois : '.$parsed['file'].' puis '.$arg.'.');
        }
    }

    return $parsed;
}

function usage(): void
{
    say('Soumet un article à DoliNews depuis un fichier Markdown.');
    say('');
    say('  php scripts/publish-article.php <fichier.md> [--dry-run] [--draft] [--editor=slug]');
    say('  php scripts/publish-article.php <fichier.md> --check');
    say('  php scripts/publish-article.php <fichier.md> --revise=<id> --motive="..."');
    say('  php scripts/publish-article.php --init [fichier.md] [--module=<chemin>]');
    say('');
    say('Le fichier porte son en-tête entre deux lignes ---, puis le corps en Markdown :');
    say('');
    say('  ---');
    say('  title: "MonModule 2.1 : import des factures fournisseur"');
    say('  summary: "Une phrase ou deux, 500 caractères au plus."');
    say('  type: release            # release ou announcement');
    say('  version: "2.1.0"');
    say('  focus: feature_major     # '.implode(', ', FOCUSES));
    say('  project: monmodule       # slug de la fiche projet, facultatif');
    say('  maturity: stable         # '.implode(', ', MATURITIES));
    say('  compat_status: declared  # '.implode(', ', COMPAT_STATUSES));
    say('  dolibarr_min: 18');
    say('  dolibarr_max: 22');
    say('  locale: fr_FR            # par défaut '.DEFAULT_LOCALE);
    say('  editor: mon-editeur      # si le compte appartient à plusieurs éditeurs');
    say('  ---');
    say('');
    say('  ## Ce que la version apporte');
    say('');
    say('  Le texte de l\'annonce. Les images en chemin relatif sont déposées');
    say('  automatiquement : ![Écran de configuration](captures/config.png)');
    say('');
    say('Seuls title, summary et type sont obligatoires.');
    say('');
    say('Un fichier annonce.en_US.md posé à côté de annonce.md part comme');
    say('traduction du même article, rattachée à lui.');
    say('');
    say('Options :');
    say('  --check            vérifier le fichier sans réseau ni jeton (utile en intégration)');
    say('  --init             écrire un squelette lu dans le descripteur du module');
    say('  --module=<chemin>  répertoire du module pour --init (par défaut, le courant)');
    say('  --dry-run          tout vérifier, éditeur compris, sans rien envoyer');
    say('  --draft            créer le brouillon sans le soumettre à la revue');
    say('  --revise=<id>      proposer une correction d\'un article déjà publié');
    say('  --motive="..."     motif de la correction, obligatoire avec --revise');
    say('  --editor=slug      éditeur sous lequel publier');
    say('  --no-translations  ne pas envoyer les fichiers de traduction voisins');
    say('');
    say('Variables d\'environnement :');
    say('  DOLINEWS_API_TOKEN     jeton personnel du compte contributeur (obligatoire)');
    say('  DOLINEWS_API_BASE      racine de l\'API (par défaut '.API_BASE.')');
    say('  DOLINEWS_EDITOR_NAME   nom de l\'éditeur à créer si le compte n\'en a aucun');
    say('  DOLINEWS_EDITOR_EMAIL  son courriel de contact, non publié');
}

/**
 * Read, parse and check one article file.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function readArticleFile(string $path, bool $isTranslation = false): array
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
 * Write a header filled from the module descriptor.
 *
 * The version and the Dolibarr release are read where they already
 * live, in core/modules/modXxx.class.php: an author asked to retype
 * them will get one wrong eventually, and a wrong compatibility is what
 * the feed must not carry (SPEC 4.2).
 *
 * @param  array{file: string, module: string, ...}  $options
 */
function writeSkeleton(array $options): int
{
    $module = readModuleDescriptor($options['module']);

    if ($module['version'] === '') {
        say('Avertissement : aucun descripteur de module lisible dans '.$options['module']
            .'. Le squelette part avec des champs vides.');
    }

    $title = trim($module['name'].' '.$module['version']);

    $file = renderArticleFile(
        [
            'title' => $title,
            'summary' => $module['summary'],
            'type' => $module['version'] !== '' ? 'release' : 'announcement',
            'version' => $module['version'],
            'focus' => '',
            'project' => $module['slug'],
            'maturity' => 'stable',
            'compat_status' => 'declared',
            'dolibarr_min' => $module['dolibarr_min'],
            'dolibarr_max' => '',
            'locale' => DEFAULT_LOCALE,
        ],
        "## Ce que la version apporte\n\n"
            ."Décrivez ici ce que cette version change pour celui qui l'installe.\n\n"
            ."## Corrections\n\n"
            ."- \n\n"
            ."## Prérequis\n",
        [
            'title' => 'complétez : ce que la version apporte, en quelques mots',
            'summary' => 'deux phrases au plus, elles servent de chapeau dans le fil',
            'focus' => implode(', ', FOCUSES),
            'project' => 'slug de la fiche projet sur le service, ou retirez la ligne',
            'compat_status' => 'declared tant que personne n\'a testé',
            'dolibarr_max' => 'dernière version vérifiée, si vous en avez une',
        ],
    );

    if ($options['file'] === '') {
        echo $file;

        return 0;
    }

    if (file_exists($options['file'])) {
        fail('Ce fichier existe déjà : '.$options['file'].'. Rien n\'a été écrit.');
    }

    if (file_put_contents($options['file'], $file) === false) {
        fail('Écriture impossible : '.$options['file']);
    }

    say('Squelette écrit : '.$options['file']);
    say('Complétez-le, puis vérifiez-le : php '.basename(__FILE__).' '.$options['file'].' --check');

    return 0;
}

/**
 * Report what can be checked without the network, and say so plainly.
 *
 * @param  array<string, mixed>  $meta
 * @param  array{translations: bool, ...}  $options
 */
function reportCheck(string $path, array $meta, string $body, array $options): int
{
    say('Type    : '.$meta['type'].(isset($meta['version']) ? ' '.$meta['version'] : ''));
    say('Résumé  : '.mb_strlen($meta['summary']).' caractères sur '.MAX_SUMMARY);
    say('Corps   : '.mb_strlen($body).' caractères sur '.MAX_BODY);

    foreach (localImages($body) as $reference => $alt) {
        $file = resolveImagePath($reference, dirname($path));

        if (! is_readable($file)) {
            fail('Image introuvable : "'.$reference.'" (cherchée dans '.$file.').');
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            fail('Image refusée : '.$reference.'. Le service n\'accepte pas le SVG.');
        }

        say('Image   : '.$reference.' ('.ceil(filesize($file) / 1024).' Ko)'
            .($alt === '' ? ' - sans texte alternatif' : ''));
    }

    if ($options['translations']) {
        foreach (siblingTranslations($path, $meta['locale']) as $locale => $file) {
            readArticleFile($file, true);
            say('Traduction '.$locale.' : '.basename($file).', recevable');
        }
    }

    say('');
    say('Ce fichier est recevable. Rien n\'a été envoyé : --check ne touche pas au réseau.');

    return 0;
}

/**
 * Propose a correction of an already published article (SPEC 5.4).
 *
 * @param  array{revise: int, motive: string, dryRun: bool, ...}  $options
 * @param  array<string, mixed>  $meta
 */
function submitRevision(array $options, array $meta, string $body): int
{
    $motive = $options['motive'] !== '' ? $options['motive'] : (string) ($meta['motive'] ?? '');

    if (trim($motive) === '') {
        fail('Une correction demande son motif : --motive="corrige la version minimale annoncée", '
            .'ou une ligne "motive:" dans l\'en-tête. Il est lu par l\'équipe de revue et reste '
            .'attaché à la révision.');
    }

    checkLength('--motive', 'motive', $motive, MAX_MOTIVE);

    // A revision carries no media list (POST /articles/{id}/revisions),
    // so an image deposited for it would stay unattached and be purged
    // as an orphan. Better refused here than silently lost tomorrow.
    if (localImages($body) !== []) {
        fail('Une correction ne peut pas introduire d\'image locale : l\'interface des révisions '
            .'ne les rattache pas, et une image non rattachée est purgée. Reprenez les adresses '
            .'des images déjà en ligne dans l\'article.');
    }

    if ($options['dryRun']) {
        say('');
        say('--dry-run : correction de l\'article #'.$options['revise'].' non envoyée.');
        say('Motif : '.$motive);

        return 0;
    }

    $revision = apiPost('/articles/'.$options['revise'].'/revisions', [
        'title' => $meta['title'],
        'summary' => $meta['summary'],
        'body' => $body,
        'motive' => $motive,
    ]);

    say('');
    say('Correction #'.$revision['revision_id'].' proposée sur l\'article #'.$options['revise'].'.');
    say('Champs modifiés : '.implode(', ', (array) ($revision['changed_fields'] ?? [])));
    say('Elle repasse par la revue : un article publié ne se modifie pas en place, '
        .'et la version d\'origine reste consultable (SPEC 5.4).');

    return 0;
}

/**
 * Say what a quota refusal means for the author, and what clears it.
 *
 * The two limits of SPEC 5.3 do not wait for the same thing: the queue
 * ceiling clears as the team reviews, the bucket only with time. An
 * author told "429" learns nothing and tries again in a minute.
 */
function failOnQuota(string $code): never
{
    if ($code === 'QUEUE_CEILING_REACHED') {
        fail('Votre éditeur a déjà autant d\'articles en revue que le service en accepte '
            .'simultanément. Rien n\'est perdu : attendez que l\'équipe en traite, puis '
            .'relancez cette commande.');
    }

    fail('Le crédit de publication de ce projet est épuisé : le service limite le rythme '
        .'des annonces d\'un même projet (SPEC 5.3). Il se reconstitue avec le temps, '
        .'sans démarche.');
}

/**
 * The translation files sitting next to the article, keyed by locale.
 *
 * Convention rather than declaration: annonce.md and annonce.en_US.md
 * belong together, and nothing has to be listed anywhere. The source's
 * own locale is skipped, whichever of the two names it carries.
 *
 * @return array<string, string>
 */
function siblingTranslations(string $path, string $sourceLocale): array
{
    $directory = dirname($path);
    $base = basename($path, '.md');
    $base = preg_replace('/\.[a-z]{2}_[A-Z]{2}$/', '', $base) ?? $base;

    $found = [];

    foreach (glob($directory.'/'.$base.'.*.md') ?: [] as $candidate) {
        if (realpath($candidate) === realpath($path)) {
            continue;
        }

        if (preg_match('/\.([a-z]{2}_[A-Z]{2})\.md$/', basename($candidate), $match) !== 1) {
            continue;
        }

        if ($match[1] === $sourceLocale) {
            continue;
        }

        $found[$match[1]] = $candidate;
    }

    ksort($found);

    return $found;
}

/**
 * Submit one translation of the article just created (SPEC 4.3, D14).
 *
 * Images are not deposited again: a translation shows the same
 * screenshots, and the interface carries no media list anyway. The
 * paths already deposited for the source are reused, and anything else
 * is refused rather than left pointing at a disk.
 *
 * @param  array<string, string>  $images  relative path => URL, from the source
 * @param  array{draft: bool, ...}  $options
 */
function submitTranslation(int $articleId, string $file, string $locale, array $images, array $options): void
{
    [$meta, $body] = readArticleFile($file, true);

    foreach (localImages($body) as $reference => $alt) {
        if (! isset($images[$reference])) {
            fail('La traduction '.basename($file).' cite une image absente de l\'article '
                .'d\'origine : "'.$reference.'". Une traduction réutilise les captures de '
                .'l\'original ; ajoutez-la d\'abord à celui-ci.');
        }

        $body = str_replace('!['.$alt.']('.$reference.')', '!['.$alt.']('.$images[$reference].')', $body);
    }

    $translation = apiPost('/articles/'.$articleId.'/translations', [
        'locale' => $locale,
        'title' => $meta['title'],
        'summary' => $meta['summary'],
        'body' => $body,
        'submit' => ! $options['draft'],
    ]);

    say('Traduction '.$locale.' : article #'.$translation['id'].' ['.$translation['status'].']');
}

/**
 * Split the header from the body.
 *
 * The header is deliberately read by hand rather than by a YAML parser:
 * the format is a flat list of scalars, and a dependency to install
 * would defeat the point of a script an author drops into a repository.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function splitFrontMatter(string $contents, string $path): array
{
    // A byte order mark ahead of the opening --- is common enough on
    // files edited under Windows to be worth skipping silently.
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $normalised = str_replace(["\r\n", "\r"], "\n", $contents);

    if (! str_starts_with($normalised, "---\n")) {
        fail('En-tête absent dans '.$path.' : le fichier doit commencer par une ligne "---". '
            .'Lancez --help pour un exemple complet.');
    }

    $end = strpos($normalised, "\n---", 4);

    if ($end === false) {
        fail('En-tête non refermé dans '.$path.' : il manque la ligne "---" qui le clôt.');
    }

    $header = substr($normalised, 4, $end - 4);
    $body = ltrim(substr($normalised, $end + 4), "\n");

    $meta = [];

    foreach (explode("\n", $header) as $number => $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, ':');

        if ($separator === false) {
            fail('En-tête de '.$path.', ligne '.($number + 2).' : "'.$line.'" '
                .'n\'est pas de la forme "cle: valeur".');
        }

        $key = strtolower(trim(substr($line, 0, $separator)));
        $value = trim(substr($line, $separator + 1));

        // Trailing comments, but only outside a quoted value: a title
        // may legitimately contain a #.
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $hash = strpos($value, '#');
            $value = $hash === false ? $value : rtrim(substr($value, 0, $hash));
        }

        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        } elseif (preg_match('/^(["\'])(.*)\1\s*#/', $value, $quoted) === 1) {
            // A quoted value carrying a trailing comment, which the test
            // above cannot see since the line no longer ends on the
            // quote. --init writes one on every field it fills, so a
            // title left with its hint would be submitted hint included.
            $value = $quoted[2];
        }

        $meta[$key] = $value;
    }

    return [$meta, $body];
}

/**
 * Check the header before a single call goes out.
 *
 * Every refusal names the file, the field and what was expected: the
 * author is not expected to know the API, only their own file.
 *
 * @param  array<string, string>  $meta
 * @return array<string, mixed>
 */
function checkMetadata(array $meta, string $path, bool $isTranslation = false): array
{
    foreach (['title', 'summary', 'type'] as $required) {
        if (trim($meta[$required] ?? '') === '') {
            fail('En-tête de '.$path.' : le champ "'.$required.'" est obligatoire.');
        }
    }

    $checked = [
        'title' => $meta['title'],
        'summary' => $meta['summary'],
        'type' => $meta['type'],
        'locale' => $meta['locale'] ?? DEFAULT_LOCALE,
    ];

    checkLength($path, 'title', $checked['title'], MAX_TITLE);
    checkLength($path, 'summary', $checked['summary'], MAX_SUMMARY);

    if (! in_array($checked['type'], TYPES, true)) {
        fail('En-tête de '.$path.' : "type" vaut "'.$checked['type'].'", attendu '
            .implode(' ou ', TYPES).'.');
    }

    if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $checked['locale']) !== 1) {
        fail('En-tête de '.$path.' : "locale" vaut "'.$checked['locale'].'", attendu une '
            .'langue de la forme fr_FR.');
    }

    foreach (['focus' => FOCUSES, 'maturity' => MATURITIES, 'compat_status' => COMPAT_STATUSES] as $field => $allowed) {
        if (($meta[$field] ?? '') === '') {
            continue;
        }

        if (! in_array($meta[$field], $allowed, true)) {
            fail('En-tête de '.$path.' : "'.$field.'" vaut "'.$meta[$field].'", attendu '
                .implode(', ', $allowed).'.');
        }

        $checked[$field] = $meta[$field];
    }

    if (($meta['version'] ?? '') !== '') {
        checkLength($path, 'version', $meta['version'], MAX_VERSION);
        $checked['version'] = $meta['version'];
    }

    foreach (['dolibarr_min', 'dolibarr_max'] as $field) {
        if (($meta[$field] ?? '') === '') {
            continue;
        }

        if (preg_match('/^\d{1,2}$/', $meta[$field]) !== 1) {
            fail('En-tête de '.$path.' : "'.$field.'" vaut "'.$meta[$field].'", attendu un '
                .'numéro de version majeure de Dolibarr, entre 1 et 99.');
        }

        $checked[$field] = (int) $meta[$field];
    }

    if (isset($checked['dolibarr_min'], $checked['dolibarr_max'])
        && $checked['dolibarr_max'] < $checked['dolibarr_min']) {
        fail('En-tête de '.$path.' : "dolibarr_max" est inférieur à "dolibarr_min".');
    }

    // focus qualifies a release: on an announcement the service drops
    // it, so a header that sets it is a misunderstanding worth naming
    // rather than a value to discard silently (SPEC 4.3).
    if ($checked['type'] === 'announcement' && isset($checked['focus'])) {
        fail('En-tête de '.$path.' : "focus" ne s\'applique qu\'aux annonces de version '
            .'(type: release), pas aux annonces libres.');
    }

    // A translation carries no version of its own: the source states
    // it, and POST /articles/{id}/translations takes none.
    if ($checked['type'] === 'release' && ! isset($checked['version']) && ! $isTranslation) {
        say('Avertissement : type "release" sans "version" dans l\'en-tête. '
            .'La revue demandera vraisemblablement laquelle.');
    }

    foreach (['project', 'editor', 'motive'] as $free) {
        if (($meta[$free] ?? '') !== '') {
            $checked[$free] = $meta[$free];
        }
    }

    return $checked;
}

function checkLength(string $path, string $field, string $value, int $max): void
{
    $length = mb_strlen($value);

    if ($length > $max) {
        fail('En-tête de '.$path.' : "'.$field.'" fait '.$length.' caractères, '
            .$max.' au plus.');
    }
}

/**
 * The body must exist and fit: an empty file below the header is the
 * most common mistake, and it reads as an obscure API refusal.
 */
function checkBody(string $body, string $path): string
{
    $body = rtrim($body)."\n";

    if (trim($body) === '') {
        fail('Corps vide dans '.$path.' : le texte de l\'annonce se place sous l\'en-tête.');
    }

    $length = mb_strlen($body);

    if ($length > MAX_BODY) {
        fail('Corps de '.$path.' : '.$length.' caractères, '.MAX_BODY.' au plus.');
    }

    return $body;
}

/**
 * Look the project sheet up by its slug.
 *
 * Never created here: a sheet is persistent and describes a module for
 * good, while this script sends one dated article (SPEC 4.2). Creating
 * one as a side effect of a release note would leave half-filled sheets
 * behind.
 *
 * @param  array<string, mixed>  $editor
 * @return array<string, mixed>
 */
function resolveProject(string $slug, array $editor): array
{
    if ($slug === '') {
        return [];
    }

    $project = apiGetOrNull('/projects/'.rawurlencode($slug));

    if ($project === null) {
        fail('Aucune fiche projet ne porte le slug "'.$slug.'". Créez-la depuis votre compte, '
            .'ou retirez la ligne "project:" de l\'en-tête : une annonce peut vivre sans fiche.');
    }

    if (($project['editor']['slug'] ?? null) !== null
        && $project['editor']['slug'] !== $editor['slug']) {
        say('Avertissement : la fiche "'.$slug.'" appartient à l\'éditeur "'
            .$project['editor']['slug'].'", pas à "'.$editor['slug'].'".');
    }

    say('Projet  : '.$project['name'].' (#'.$project['id'].')');

    return $project;
}

/**
 * The images the body points at with a local path, alternative text per
 * reference. Absolute URLs are left alone: they are already served.
 *
 * @return array<string, string>
 */
function localImages(string $body): array
{
    // Code spans and fenced blocks are read by humans, not by the
    // renderer: an article explaining the Markdown syntax of an image
    // must not see that example deposited, nor refused for a file that
    // was never meant to exist.
    $searchable = (string) preg_replace(['/```.*?```/s', '/`[^`]*`/'], '', $body);

    if (preg_match_all('/!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/', $searchable, $matches, PREG_SET_ORDER) === 0) {
        return [];
    }

    $local = [];

    foreach ($matches as [, $alt, $reference]) {
        if (preg_match('#^(https?:)?//#i', $reference) === 1 || str_starts_with($reference, 'data:')) {
            continue;
        }

        $local[$reference] = $alt;
    }

    return $local;
}

function resolveImagePath(string $reference, string $directory): string
{
    return str_starts_with($reference, '/') ? $reference : $directory.'/'.$reference;
}

/**
 * Deposit the images the body points at with a relative path, and
 * rewrite the body with the URLs the service serves them under.
 *
 * The alternative text of the Markdown link becomes the alternative
 * text of the media: an author who wrote one does not write it twice.
 *
 * @param  array<string, mixed>  $editor
 * @return array{0: string, 1: list<int>, 2: array<string, string>}
 */
function depositImages(string $body, string $directory, array $editor, bool $dryRun): array
{
    $deposited = [];
    $ids = [];

    foreach (localImages($body) as $reference => $alt) {
        $file = resolveImagePath($reference, $directory);

        if (! is_readable($file)) {
            fail('Image introuvable : "'.$reference.'" (cherchée dans '.$file.'). '
                .'Les chemins sont relatifs au fichier Markdown.');
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            fail('Image refusée : '.$reference.'. Le service n\'accepte pas le SVG, '
                .'qui peut porter du script. Exportez la capture en PNG.');
        }

        if (count($deposited) >= MAX_MEDIA) {
            fail('Plus de '.MAX_MEDIA.' images dans ce corps : c\'est le maximum '
                .'qu\'un article peut porter.');
        }

        if ($dryRun) {
            say('    image à déposer : '.$reference);
            $deposited[$reference] = $reference;

            continue;
        }

        $media = apiUpload('/media', $file, array_filter([
            'editor_id' => (string) $editor['id'],
            'alt' => $alt,
        ], static fn (string $value): bool => $value !== ''));

        say('    image déposée : '.$reference.' -> '.$media['url']);

        $deposited[$reference] = (string) $media['url'];
        $ids[] = (int) $media['id'];
        $body = str_replace('!['.$alt.']('.$reference.')', '!['.$alt.']('.$media['url'].')', $body);
    }

    return [$body, $ids, $deposited];
}
