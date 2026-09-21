<?php

declare(strict_types=1);

/**
 * Shared client for the scripts that submit content to a DoliNews
 * instance through the public API (SPEC 5.2).
 *
 * Every publication script used to carry its own copy of these helpers.
 * They are identical from one script to the next, so a fix to the rate
 * limit handling or to the error reporting only ever reached the script
 * it was written in. They live here now; a script keeps its own
 * configuration constants and its own content, which is what actually
 * differs between them.
 *
 * The client only ever talks to /api/v1, and never to the database. A
 * token grants the right to SUBMIT, never to publish: whatever these
 * scripts send lands in the review queue (SPEC 5.1/D12).
 *
 * Usage:
 *   require_once __DIR__.'/lib/dolinews-client.php';
 *   dolinews_configure(API_BASE, API_TOKEN);
 */

/**
 * Base URL and personal token used by every later call.
 *
 * Passed in rather than read from constants: the constants belong to the
 * calling script, where an operator expects to find them, and a library
 * that reaches into its caller's namespace cannot be tested on its own.
 */
function dolinews_configure(string $apiBase, string $apiToken): void
{
    if ($apiToken === '') {
        fail('Renseignez API_TOKEN en tête de script : un jeton personnel obtenu depuis le compte contributeur.');
    }

    if (! function_exists('curl_init')) {
        fail('L\'extension curl de PHP est requise.');
    }

    dolinews_config('base', rtrim($apiBase, '/'));
    dolinews_config('token', $apiToken);
}

/**
 * Read or write one configuration entry.
 *
 * A static holder rather than globals: the value is written once by
 * dolinews_configure() and read by request(), and nothing else can
 * silently overwrite it halfway through a run.
 */
function dolinews_config(string $key, ?string $value = null): string
{
    /** @var array<string, string> $store */
    static $store = [];

    if ($value !== null) {
        $store[$key] = $value;
    }

    if (! isset($store[$key])) {
        fail('Client API non configuré : appelez dolinews_configure() avant toute requête.');
    }

    return $store[$key];
}

/**
 * Check the account may write at all, and return its profile.
 *
 * A reader account can read and subscribe, never write (SPEC 3.1).
 * Stopping here names the reason, rather than letting the first write
 * come back as a bare CONTRIBUTOR_REQUIRED.
 *
 * @return array<string, mixed>
 */
function requireContributorProfile(): array
{
    $profile = apiGet('/profile');

    if (($profile['is_contributor'] ?? false) !== true) {
        fail('Ce compte n\'est pas contributeur : il peut lire et s\'abonner, jamais écrire (SPEC 3.1).');
    }

    return $profile;
}

/**
 * Pick the editor to publish for: the configured slug, or the only one
 * the account belongs to. Several editors without a configured slug is
 * an ambiguity the script refuses to resolve by itself, since the choice
 * signs the publication.
 *
 * An account owning none gets one created from $fallback: one never
 * publishes under one's own name, always under an editor, and a run that
 * stopped to send the operator to a web form for a single form would be
 * a poor integration.
 *
 * @param  array<int, array<string, mixed>>  $editors  profile editors
 * @param  array{slug?: string, name?: string, contact_email?: string, website?: string, description?: string}  $fallback
 * @return array<string, mixed>
 */
function resolveEditor(array $editors, bool $dryRun, array $fallback): array
{
    $slug = $fallback['slug'] ?? '';
    $name = $fallback['name'] ?? '';

    if ($editors === []) {
        if (($fallback['contact_email'] ?? '') === '') {
            fail('Ce compte n\'appartient à aucun éditeur, et EDITOR_CONTACT_EMAIL est vide : '
                .'renseignez le courriel de contact en tête de script pour que l\'éditeur "'
                .$name.'" soit créé.');
        }

        if ($dryRun) {
            say('Éditeur à créer : '.$name);

            return ['id' => 0, 'slug' => 'a-creer', 'name' => $name, 'role' => 'owner'];
        }

        $created = apiPost('/editors', array_filter([
            'name' => $name,
            'contact_email' => $fallback['contact_email'] ?? '',
            'website' => $fallback['website'] ?? '',
            'description' => $fallback['description'] ?? '',
        ], static fn (string $value): bool => $value !== ''));

        say('Éditeur créé : '.$created['name'].' (#'.$created['id'].'), vous en êtes le propriétaire.');

        // The creating account owns what it just created; the profile
        // is not read again for a single field.
        return $created + ['role' => 'owner'];
    }

    if ($slug !== '') {
        foreach ($editors as $candidate) {
            if ($candidate['slug'] === $slug) {
                return $candidate;
            }
        }

        fail('Aucun éditeur de ce compte ne porte le slug '.$slug.'.');
    }

    if (count($editors) > 1) {
        fail('Ce compte appartient à plusieurs éditeurs : renseignez EDITOR_SLUG parmi '
            .implode(', ', array_column($editors, 'slug')).'.');
    }

    return $editors[0];
}

/**
 * Replace the {{media:key}} and {{alt:key}} placeholders of a body by
 * the URLs and alternative texts of the deposited screenshots. Only
 * media served by the service illustrate an article (SPEC 5.2/7): an
 * unresolved placeholder would produce an image the renderer drops.
 *
 * @param  array<string, array<string, mixed>>  $media  deposited media, keyed like the placeholders
 * @param  array<string, string>  $alts  alternative text per key
 */
function expand(string $body, array $media, array $alts): string
{
    foreach ($media as $key => $deposited) {
        $body = str_replace(
            ['{{media:'.$key.'}}', '{{alt:'.$key.'}}'],
            [(string) $deposited['url'], $alts[$key] ?? ''],
            $body,
        );
    }

    if (preg_match('/\{\{(media|alt):([a-z0-9-]+)\}\}/', $body, $match) === 1) {
        fail('Référence de capture inconnue dans un corps d\'article : '.$match[0]);
    }

    return $body;
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
 * Same as apiPost, but the caller handles the failure itself.
 *
 * Used where a refusal is an expected outcome rather than a bug: the
 * queue ceiling and the empty token bucket both answer 429, and a run
 * that submits a series must be able to stop on them without treating
 * what it already sent as lost (SPEC 5.3).
 *
 * @param  array<string, mixed>  $payload
 * @return array{status: int, data: array<string, mixed>, error: string}
 */
function apiPostAllowingFailure(string $path, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        fail('Encodage JSON impossible pour '.$path.' : '.json_last_error_msg());
    }

    $response = request('POST', $path, $json, [
        'Content-Type: application/json',
    ]);

    return [
        'status' => $response['status'],
        'data' => $response['body']['data'] ?? [],
        'error' => is_string($response['body']['error'] ?? null)
            ? (string) $response['body']['error']
            : '',
    ];
}

/**
 * Deposit one image (multipart), step one of an illustrated publication.
 *
 * @param  array<string, string>  $fields
 * @return array<string, mixed>
 */
function apiUpload(string $path, string $file, array $fields): array
{
    if (! is_readable($file)) {
        fail('Fichier introuvable ou illisible : '.$file);
    }

    $payload = $fields + [
        'file' => new CURLFile($file, mimeOf($file), basename($file)),
    ];

    $response = request('POST', $path, $payload);

    if ($response['status'] !== 200 && $response['status'] !== 201) {
        fail('POST '.$path.' a répondu '.$response['status'].' : '.describe($response['body']));
    }

    return $response['body']['data'] ?? [];
}

/**
 * One HTTP call, retried once the write throttle clears: the API allows
 * ten writes per minute and these scripts make more than ten.
 *
 * @param  string|array<string, mixed>  $body
 * @param  list<string>  $headers
 * @return array{status: int, body: array<string, mixed>}
 */
function request(string $method, string $path, string|array $body = '', array $headers = []): array
{
    $attempt = 0;

    while (true) {
        $handle = curl_init(dolinews_config('base').$path);

        if ($handle === false) {
            fail('Initialisation curl impossible pour '.$path.'.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge([
                'Authorization: Bearer '.dolinews_config('token'),
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

        // Only the rate limiter is worth waiting out. A quota refusal
        // answers 429 too, and retrying it would sleep three minutes to
        // be refused again for the same reason (SPEC 5.3).
        if (($decoded['error'] ?? '') !== 'RATE_LIMITED') {
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

/**
 * Declared MIME type of an upload. The service never trusts it: it reads
 * the actual header and re-encodes whatever it finds (SPEC 7).
 */
function mimeOf(string $file): string
{
    $info = @getimagesize($file);

    return $info === false ? 'application/octet-stream' : (string) $info['mime'];
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
