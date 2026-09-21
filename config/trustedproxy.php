<?php

declare(strict_types=1);

return [

    // Vide = exposition directe. Lu par TrustProxies a chaque requete, et par
    // HoneypotReporter pour refuser de signaler le frontal lui-meme.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))) ?: null,

];
