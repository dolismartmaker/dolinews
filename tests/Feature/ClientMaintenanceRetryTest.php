<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The publishing client while the service is being updated.
 *
 * It used to print the first 200 characters of the HTML maintenance page
 * and stop, in the middle of a CI job, where waiting a few minutes was all
 * it took. It now waits for the Retry-After the service announces and
 * sends the call again, then fails with a sentence a person can act on.
 *
 * Driven like ProjectSheetEditorTest: the real script, against a throwaway
 * API served by php -S in the project port window (capdoc:PORTS.md).
 *
 * @param  string  $maintenance  what the API answers while down: 'html'
 *                               (the page an instance served before it
 *                               answered in JSON) or 'json' (the envelope)
 * @param  int  $downCalls  how many calls answer 503 before the API is back
 * @return array{0: Process, 1: string, 2: string} server, API base URL, router path
 */
function startMaintenanceApi(string $maintenance, int $downCalls): array
{
    $base = (int) getenv('DOLINEWS_TEST_BACKEND_PORT');

    if ($base <= 0) {
        test()->markTestSkipped('DOLINEWS_TEST_BACKEND_PORT is not set: no port reserved for the fake API.');
    }

    $router = sys_get_temp_dir().'/dolinews-maintenance-api-'.uniqid().'.php';
    file_put_contents($router, str_replace(
        ['__MODE__', '__DOWN__'],
        [$maintenance, (string) $downCalls],
        <<<'PHP'
            <?php
            $calls = is_file(__FILE__.'.calls') ? (int) file_get_contents(__FILE__.'.calls') : 0;
            file_put_contents(__FILE__.'.calls', (string) ($calls + 1));
            if ($calls < __DOWN__) {
                http_response_code(503);
                header('Retry-After: 1');
                if ('__MODE__' === 'html') {
                    echo '<!DOCTYPE html><html lang="fr"><head><meta name="robots" content="noindex"></head><body>Mise à jour en cours</body></html>';
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'MAINTENANCE', 'message' => 'Le service est en cours de mise à jour.']);
                }
                return;
            }
            header('Content-Type: application/json');
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/api/v1/profile') {
                echo json_encode(['data' => [
                    'is_contributor' => true,
                    'editors' => [['id' => 7, 'slug' => 'cap-rel', 'name' => 'CAP-REL', 'role' => 'owner']],
                ]]);
                return;
            }
            http_response_code(404);
            echo json_encode(['error' => 'NOT_FOUND']);
            PHP,
    ));

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

/**
 * Run the sheet script in dry-run against the fake API.
 */
function runSheetScriptAgainst(string $apiBase, string $retries): Process
{
    $sheet = sys_get_temp_dir().'/fiche-'.uniqid().'.md';
    file_put_contents($sheet, <<<'MD'
        ---
        project: capagenda
        name: "CapAgenda"
        summary: "L'agenda partagé dans Dolibarr."
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
                'DOLINEWS_EDITOR_NAME' => '',
                'DOLINEWS_EDITOR_EMAIL' => '',
                'DOLINEWS_MAINTENANCE_RETRIES' => $retries,
            ],
        );
        $process->setTimeout(60);
        $process->run();
    } finally {
        unlink($sheet);
    }

    return $process;
}

/**
 * Stop the fake API and remove its files.
 */
function stopMaintenanceApi(Process $server, string $router): void
{
    $server->stop();

    foreach ([$router, $router.'.calls'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

it('waits out the maintenance page and carries on', function (): void {
    [$server, $apiBase, $router] = startMaintenanceApi('html', 2);

    try {
        $process = runSheetScriptAgainst($apiBase, '3');
    } finally {
        stopMaintenanceApi($server, $router);
    }

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('Service en cours de mise à jour, nouvelle tentative dans 1 s (1/3)')
        ->and($output)->toContain('(2/3)')
        ->and($output)->toContain('la fiche "capagenda" serait créée')
        ->and($output)->not->toContain('<!DOCTYPE');
});

it('gives up with a sentence rather than the page once the retries are spent', function (): void {
    [$server, $apiBase, $router] = startMaintenanceApi('json', 100);

    try {
        $process = runSheetScriptAgainst($apiBase, '1');
    } finally {
        stopMaintenanceApi($server, $router);
    }

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->not->toBe(0)
        ->and($output)->toContain('Le service est en cours de mise à jour (GET /profile a répondu 503 à 2 reprises) : réessayez dans quelques minutes.')
        ->and($output)->not->toContain('MAINTENANCE');
});
