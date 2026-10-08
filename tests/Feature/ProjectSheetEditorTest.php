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
