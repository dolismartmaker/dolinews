<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * client/bin/publish-project-sheet.php picks the editor the sheet is filed
 * under. The editors of the account are the ones of its profile, as for
 * publish-article.php: reading GET /editors instead returns the public
 * directory, which the script then mistook for "no editor", and it tried
 * to create one the account already owned (409).
 *
 * The script runs outside the application and talks HTTP through curl, so
 * it is driven against a throwaway API served by php -S. The port comes
 * from the project window (DOLINEWS_TEST_BACKEND_PORT, see
 * capdoc:PORTS.md): its first port is the page preview, this test
 * takes the first free one after it.
 *
 * @return array{0: Process, 1: string, 2: string} server, API base URL, router path
 */
function startFakeDolinewsApi(): array
{
    $base = (int) getenv('DOLINEWS_TEST_BACKEND_PORT');

    if ($base <= 0) {
        test()->markTestSkipped('DOLINEWS_TEST_BACKEND_PORT is not set: no port reserved for the fake API.');
    }

    $router = sys_get_temp_dir().'/dolinews-fake-api-'.uniqid().'.php';
    file_put_contents($router, <<<'PHP'
        <?php
        header('Content-Type: application/json');
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET' && $path === '/api/v1/profile') {
            echo json_encode(['data' => [
                'is_contributor' => true,
                'editors' => [['id' => 7, 'slug' => 'cap-rel', 'name' => 'CAP-REL', 'role' => 'owner']],
            ]]);
            return;
        }
        if ($method === 'GET' && $path === '/api/v1/editors') {
            // The public directory: other editors, not the ones of the account
            echo json_encode(['data' => [['id' => 3, 'slug' => 'someone-else', 'name' => 'Someone else']]]);
            return;
        }
        // "existing" is a sheet already filed, carrying a doc link: the script
        // rewrites it by PATCH and only adds the links it lacks
        // A test states what the sheet already shows (logo, gallery) in
        // the .sheet file next to the router
        if ($method === 'GET' && $path === '/api/v1/projects/existing') {
            $extra = is_file(__FILE__.'.sheet') ? json_decode(file_get_contents(__FILE__.'.sheet'), true) : [];
            echo json_encode(['data' => array_merge(['slug' => 'existing', 'name' => 'Existing', 'links' => [
                ['type' => 'doc', 'url' => 'https://doc.example.org/existing/', 'label' => null, 'is_broken' => false],
            ], 'logo' => null, 'gallery' => []], $extra)]);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/media') {
            $line = json_encode(['name' => $_FILES['file']['name'] ?? null] + $_POST);
            file_put_contents(__FILE__.'.media', $line."\n", FILE_APPEND);
            $id = 100 + count(file(__FILE__.'.media'));
            http_response_code(201);
            echo json_encode(['data' => ['id' => $id, 'url' => 'https://dolinews.test/media/'.$id.'.png']]);
            return;
        }
        if ($method === 'PUT' && $path === '/api/v1/projects/existing/logo') {
            file_put_contents(__FILE__.'.logo', file_get_contents('php://input'));
            echo json_encode(['data' => ['slug' => 'existing']]);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/projects/existing/gallery') {
            file_put_contents(__FILE__.'.gallery', file_get_contents('php://input')."\n", FILE_APPEND);
            http_response_code(201);
            echo json_encode(['data' => ['media_id' => 1]]);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/projects/existing/links') {
            file_put_contents(__FILE__.'.links', file_get_contents('php://input')."\n", FILE_APPEND);
            http_response_code(201);
            echo json_encode(['data' => ['id' => 1]]);
            return;
        }
        if ($method === 'PATCH' && $path === '/api/v1/projects/existing') {
            file_put_contents(__FILE__.'.patch', file_get_contents('php://input'));
            echo json_encode(['data' => ['slug' => 'existing', 'name' => 'Existing']]);
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/editors') {
            http_response_code(409);
            echo json_encode(['error' => 'CONFLICT', 'message' => 'This account already owns an editor.']);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'NOT_FOUND']);
        PHP);

    for ($port = $base + 1; $port <= $base + 4; $port++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($probe !== false) {
            fclose($probe);

            continue;
        }

        $server = new Process(['php', '-S', '127.0.0.1:'.$port, $router]);
        $server->start();

        for ($i = 0; $i < 50; $i++) {
            $ready = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($ready !== false) {
                fclose($ready);

                return [$server, 'http://127.0.0.1:'.$port.'/api/v1', $router];
            }

            usleep(100000);
        }

        $server->stop();
    }

    unlink($router);
    test()->markTestSkipped('No free port between '.($base + 1).' and '.($base + 4).' for the fake API.');
}

it('files the sheet under the editor of the account profile', function (): void {
    [$server, $apiBase, $router] = startFakeDolinewsApi();

    $sheet = sys_get_temp_dir().'/fiche-'.uniqid().'.md';
    file_put_contents($sheet, <<<'MD'
        ---
        project: capcowork
        name: "CapCowork"
        summary: "Gérez votre espace de coworking dans Dolibarr."
        locale: fr_FR
        ---

        ## Présentation

        Le texte de la fiche.
        MD);

    try {
        $process = new Process(
            ['php', base_path('client/bin/publish-project-sheet.php'), $sheet, '--dry-run'],
            null,
            [
                'DOLINEWS_API_BASE' => $apiBase,
                'DOLINEWS_API_TOKEN' => 'test-token',
                // Empty on purpose: with the account editor found, nothing is created
                'DOLINEWS_EDITOR_NAME' => '',
                'DOLINEWS_EDITOR_EMAIL' => '',
            ],
        );
        $process->run();
    } finally {
        $server->stop();
        unlink($router);
        unlink($sheet);
    }

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('la fiche "capcowork" serait créée')
        ->and($output)->not->toContain('aucun éditeur');
});

it('rewrites a sheet already filed', function (): void {
    // apiPatch() used to destructure the response of request() as a list
    // while it is keyed by status and body: every rewrite died on a
    // TypeError, after the PATCH had been sent.
    [$server, $apiBase, $router] = startFakeDolinewsApi();

    $sheet = sys_get_temp_dir().'/fiche-'.uniqid().'.md';
    file_put_contents($sheet, <<<'MD'
        ---
        project: existing
        name: "Existing"
        summary: "Le résumé réécrit."
        locale: fr_FR
        link_doc: https://doc.example.org/existing/
        link_demo: https://demo.example.org/existing/
        ---

        ## Présentation

        Le texte réécrit.
        MD);

    try {
        $process = new Process(
            ['php', base_path('client/bin/publish-project-sheet.php'), $sheet],
            null,
            [
                'DOLINEWS_API_BASE' => $apiBase,
                'DOLINEWS_API_TOKEN' => 'test-token',
                'DOLINEWS_EDITOR_NAME' => '',
                'DOLINEWS_EDITOR_EMAIL' => '',
            ],
        );
        $process->run();
        $sent = is_file($router.'.patch') ? json_decode((string) file_get_contents($router.'.patch'), true) : null;
        $links = is_file($router.'.links')
            ? array_map(static fn (string $line): mixed => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($router.'.links'))))
            : [];
    } finally {
        $server->stop();
        @unlink($router.'.patch');
        @unlink($router.'.links');
        unlink($router);
        unlink($sheet);
    }

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('Fiche corrigée : existing')
        ->and($sent)->toBeArray()
        ->and($sent['summary'] ?? null)->toBe('Le résumé réécrit.')
        ->and($sent)->not->toHaveKey('link_doc')
        // The doc link is already on the sheet: sending it again would
        // duplicate it, the service keeps every link it is sent
        ->and(array_values($links))->toBe([['type' => 'demo', 'url' => 'https://demo.example.org/existing/']])
        ->and($output)->toContain('Lien demo ajouté');
});

it('refuses an unknown link type before any network', function (): void {
    $sheet = sys_get_temp_dir().'/fiche-'.uniqid().'.md';
    file_put_contents($sheet, <<<'MD'
        ---
        project: capcowork
        name: "CapCowork"
        summary: "Gérez votre espace de coworking dans Dolibarr."
        link_tests: https://demo.example.org/capcowork/
        ---

        Le texte de la fiche.
        MD);

    $process = new Process(['php', base_path('client/bin/publish-project-sheet.php'), $sheet, '--check']);
    $process->run();
    unlink($sheet);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('"link_tests" n\'est pas un type de lien connu');
});

/**
 * A small PNG written next to a sheet file.
 */
function sheetImage(string $path, int $width): void
{
    $image = imagecreatetruecolor($width, 30);

    if ($image === false) {
        throw new RuntimeException('GD image creation failed');
    }

    imagepng($image, $path);
}

/**
 * Lines of a JSON-lines record left by the fake API.
 *
 * @return list<mixed>
 */
function fakeApiRecord(string $file): array
{
    if (! is_file($file)) {
        return [];
    }

    return array_values(array_map(
        static fn (string $line): mixed => json_decode($line, true),
        array_filter(explode("\n", (string) file_get_contents($file))),
    ));
}

it('checks the images of a sheet before any network', function (): void {
    $directory = sys_get_temp_dir().'/fiche-'.uniqid();
    mkdir($directory.'/captures', 0777, true);
    sheetImage($directory.'/captures/accueil.png', 40);
    file_put_contents($directory.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $run = static function (string $header) use ($directory): Process {
        file_put_contents($directory.'/fiche.md', "---\nproject: capcowork\nname: \"CapCowork\"\n"
            ."summary: \"Gérez votre espace de coworking.\"\n".$header."\n---\n\nLe texte de la fiche.\n");
        $process = new Process(['php', base_path('client/bin/publish-project-sheet.php'), $directory.'/fiche.md', '--check']);
        $process->run();

        return $process;
    };

    try {
        $valid = $run("gallery: captures/accueil.png | Page d'accueil du module");
        $svg = $run('logo: logo.svg');
        $missing = $run('gallery: captures/absente.png');
        $caption = $run('gallery: captures/accueil.png | '.str_repeat('a', 256));
    } finally {
        array_map('unlink', [$directory.'/captures/accueil.png', $directory.'/logo.svg', $directory.'/fiche.md']);
        rmdir($directory.'/captures');
        rmdir($directory);
    }

    expect($valid->getExitCode())->toBe(0, $valid->getErrorOutput())
        ->and($valid->getOutput())->toContain('Capture : '.$directory.'/captures/accueil.png | Page d\'accueil du module')
        // The warning of SPEC 7 is said before anything leaves the machine
        ->and($valid->getOutput())->toContain('données de démonstration')
        ->and($svg->getExitCode())->not->toBe(0)
        ->and($svg->getErrorOutput())->toContain('Le SVG est refusé')
        ->and($missing->getExitCode())->not->toBe(0)
        ->and($missing->getErrorOutput())->toContain('introuvable ou illisible')
        ->and($caption->getExitCode())->not->toBe(0)
        ->and($caption->getErrorOutput())->toContain('le maximum est 255');
});

it('sends only the images the sheet does not show yet', function (): void {
    [$server, $apiBase, $router] = startFakeDolinewsApi();

    $directory = sys_get_temp_dir().'/fiche-'.uniqid();
    mkdir($directory);
    sheetImage($directory.'/logo.png', 20);
    sheetImage($directory.'/accueil.png', 41);
    sheetImage($directory.'/liste.png', 42);

    // accueil.png is already on the sheet, same caption, same place: the
    // sha256 of the file is what the service keeps as source_hash
    file_put_contents($router.'.sheet', json_encode(['gallery' => [[
        'media_id' => 55,
        'url' => 'https://dolinews.test/media/55.png',
        'caption' => 'Accueil',
        'position' => 0,
        'source_hash' => hash_file('sha256', $directory.'/accueil.png'),
    ]]]));

    file_put_contents($directory.'/fiche.md', <<<'MD'
        ---
        project: existing
        name: "Existing"
        summary: "Le résumé."
        locale: fr_FR
        logo: logo.png
        gallery: accueil.png | Accueil
        gallery: liste.png | Liste des relances
        ---

        Le texte.
        MD);

    $env = [
        'DOLINEWS_API_BASE' => $apiBase,
        'DOLINEWS_API_TOKEN' => 'test-token',
        'DOLINEWS_EDITOR_NAME' => '',
        'DOLINEWS_EDITOR_EMAIL' => '',
    ];

    try {
        $dryRun = new Process(['php', base_path('client/bin/publish-project-sheet.php'), $directory.'/fiche.md', '--dry-run'], null, $env);
        $dryRun->run();
        $uploadsAfterDryRun = fakeApiRecord($router.'.media');

        $process = new Process(['php', base_path('client/bin/publish-project-sheet.php'), $directory.'/fiche.md'], null, $env);
        $process->run();
        $uploads = fakeApiRecord($router.'.media');
        $gallery = fakeApiRecord($router.'.gallery');
        $logo = is_file($router.'.logo') ? json_decode((string) file_get_contents($router.'.logo'), true) : null;
    } finally {
        $server->stop();
        foreach (['', '.sheet', '.media', '.gallery', '.logo', '.patch', '.links'] as $suffix) {
            @unlink($router.$suffix);
        }
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }

    $output = $process->getOutput().$process->getErrorOutput();

    expect($dryRun->getExitCode())->toBe(0, $dryRun->getOutput().$dryRun->getErrorOutput())
        ->and($dryRun->getOutput())->toContain('Simulation : capture à envoyer : liste.png')
        ->and($dryRun->getOutput())->toContain('Simulation : logo à envoyer : logo.png')
        ->and($dryRun->getOutput())->not->toContain('accueil.png')
        ->and($uploadsAfterDryRun)->toBe([])
        ->and($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('données de démonstration')
        // accueil.png is not uploaded again; logo and liste are
        ->and(array_column($uploads, 'name'))->toBe(['logo.png', 'liste.png'])
        ->and($uploads[0]['editor_id'] ?? null)->toBe('7')
        ->and($uploads[1]['alt'] ?? null)->toBe('Liste des relances')
        ->and($logo)->toBe(['media_id' => 101])
        ->and($gallery)->toBe([['media_id' => 102, 'caption' => 'Liste des relances', 'position' => 1]]);
});
