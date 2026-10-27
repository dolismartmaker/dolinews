<?php

declare(strict_types=1);
use App\Core\Enums\ApiErrorCode;

/**
 * The API's answer while the service is down for an update.
 *
 * The deploy runs `artisan down --render` (Makefile.dist): the maintenance
 * page is prerendered, and public/index.php serves it before the Composer
 * autoloader, on a tree whose vendor/ is being rewritten. Nothing of the
 * framework runs then - the exception renderer of bootstrap/app.php
 * included - so every API client used to receive the HTML page: a
 * publishing tool printed it and stopped, where it only had to wait.
 *
 * This file runs at the same place and under the same constraint: plain
 * PHP, no autoloader. It answers the API paths with the error envelope and
 * the Retry-After of the down file. Everything else is left exactly where
 * the framework's own stub leaves it: the site gets the prerendered page,
 * and a path the maintenance excepts or a request carrying a valid bypass
 * cookie reaches the framework.
 *
 * Returns a closure so the decision can be tested without writing the
 * down file the whole application reads.
 */
return static function (string $downFile, string $requestUri, array $cookies): ?array {
    if (! is_file($downFile)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($downFile), true);

    // Without a prerendered page the framework boots, and the renderer of
    // bootstrap/app.php answers with the envelope itself.
    if (! is_array($data) || ! isset($data['template'])) {
        return null;
    }

    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $path = rawurldecode($path !== '/' ? trim($path, '/') : $path);

    if ($path !== 'api' && ! str_starts_with($path, 'api/')) {
        return null;
    }

    // Same exclusions as the framework's maintenance stub, so that the API
    // is never answered where the site would not have been.
    foreach ((array) ($data['except'] ?? []) as $except) {
        $except = $except !== '/' ? trim((string) $except, '/') : '/';
        $pattern = '#^'.str_replace('\*', '.*', preg_quote($except, '#')).'\z#u';

        if ($except === $path || preg_match($pattern, $path) === 1) {
            return null;
        }
    }

    if (isset($data['secret'], $cookies['laravel_maintenance'])) {
        $payload = json_decode((string) base64_decode((string) $cookies['laravel_maintenance']), true);

        if (is_array($payload)
            && is_numeric($payload['expires_at'] ?? null)
            && isset($payload['mac'])
            && hash_equals(hash_hmac('sha256', (string) $payload['expires_at'], (string) $data['secret']), (string) $payload['mac'])
            && (int) $payload['expires_at'] >= time()) {
            return null;
        }
    }

    // The code and its message come from the enum, read straight from its
    // file: it depends on nothing, and one wording is enough.
    if (! enum_exists('App\Core\Enums\ApiErrorCode', false)) {
        require_once dirname(__DIR__).'/app/Core/Enums/ApiErrorCode.php';
    }

    $code = ApiErrorCode::MAINTENANCE;

    $headers = [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-store',
    ];

    if (isset($data['retry'])) {
        $headers['Retry-After'] = (string) $data['retry'];
    }

    return [
        'status' => $code->httpStatus(),
        'headers' => $headers,
        'body' => (string) json_encode(
            ['error' => $code->value, 'message' => $code->message()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ),
    ];
};
