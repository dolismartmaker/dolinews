<?php

declare(strict_types=1);

/**
 * The session cookie is secure unless the environment explicitly says
 * otherwise: a .env missing the key must not serve it in clear.
 */
it('marks the session cookie secure when the environment does not set it', function (): void {
    $previous = getenv('SESSION_SECURE_COOKIE');
    putenv('SESSION_SECURE_COOKIE');
    unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

    try {
        $config = require base_path('config/session.php');
    } finally {
        if ($previous !== false) {
            putenv('SESSION_SECURE_COOKIE='.$previous);
        }
    }

    expect($config['secure'])->toBeTrue();
});
