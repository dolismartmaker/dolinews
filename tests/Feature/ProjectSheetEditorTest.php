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
 * ~/docs/TESTING_PWA.md): its first port is the page preview, this test
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
        if ($method === 'GET' && $path === '/api/v1/projects/existing') {
            echo json_encode(['data' => ['slug' => 'existing', 'name' => 'Existing', 'links' => [
                ['type' => 'doc', 'url' => 'https://doc.example.org/existing/', 'label' => null, 'is_broken' => false],
            ]]]);
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
