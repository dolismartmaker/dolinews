<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('HONEYPOT_ENABLED', true),

    // Bannissement immediat : ce qui cherche un secret.
    'instant_extensions' => [
        'env', 'git', 'htaccess', 'htpasswd', 'key', 'pem', 'crt', 'sql',
        'sqlite', 'db', 'bak', 'old', 'save', 'swp', 'swo',
    ],

    // Bannissement au quatrieme essai.
    'extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'sh', 'exe', 'dll',
        'jar', 'ini', 'log',
    ],

    'instant_paths' => [
        '.aws/credentials',
        '.git/',
        '.ssh/',
        '_ignition/execute-solution',
        'server-status',
        'telescope/requests',
        'vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
    ],

    // Reprise de doliproxy/proxymanager, config/honeypot.php. Jamais un
    // mot ambigu qu'une vraie route de ce projet pourrait porter.
    'paths' => [
        'adminer.php', 'Autodiscover/Autodiscover.xml', 'cgi-bin/kerbynet',
        'owa/auth/logon.aspx', 'phpmyadmin/', 'remote/fgt_lang', 'webfig',
        'wp-admin/', 'wp-content/', 'wp-includes/', 'wp-json/', 'xmlrpc.php',
        'azenv.php', 'HNAP1/', 'hudson/', 'jenkins/', 'mgmt/index.xml',
        'moadmin.php', 'mysql/', 'plesk-site-preview/', 'password.txt',
        'readme.txt', 'solr/', 'system/', 'typo3/', 'upload.php',
        'web-console/', 'websql/', 'wp-config.php', 'wp-login.php',
    ],

    // Evalue en premier. A adapter au projet : c'est ici que se jouent les
    // faux positifs. Rien ici n'entre en collision avec les routes
    // publiques de DoliNews (/feeds.xml, /feeds.json, /donnees, ...).
    'ignore' => [
        '.well-known/', 'apple-touch-icon', 'browserconfig.xml', 'build/',
        'favicon.ico', 'feeds', 'robots.txt', 'sitemap.xml', 'site.webmanifest',
        'storage/',
    ],

    'whitelist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HONEYPOT_IP_WHITELIST', ''))
    ))),

    // Les frontaux se declarent dans config/trustedproxy.php, pas ici.

    'fail2band' => [
        'enabled' => (bool) env('HONEYPOT_FAIL2BAND_ENABLED', false),
        'url' => env('HONEYPOT_FAIL2BAND_URL'),
        'api_key' => env('HONEYPOT_FAIL2BAND_API_KEY'),
        // Same name as the local jail rendered from deploy/fail2ban, which
        // install:fail2ban derives from the application slug: the two outputs
        // describe the same trap, and a report filed under another name is
        // impossible to correlate with a local ban.
        'jail_name' => env('HONEYPOT_FAIL2BAND_JAIL', 'dolinews-honeypot'),
        'timeout' => (int) env('HONEYPOT_FAIL2BAND_TIMEOUT', 5),
    ],

];
