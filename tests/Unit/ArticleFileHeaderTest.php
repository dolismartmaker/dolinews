<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The header of an article file is read by scripts/publish-article.php,
 * which runs outside the application, in the repository of the editor.
 * Its only surface observable from here is --check, which needs neither
 * network nor token, so the parsing is driven through it.
 */
function checkArticleFile(string $contents): Process
{
    $path = sys_get_temp_dir().'/annonce-'.uniqid().'.md';
    file_put_contents($path, $contents);

    $process = new Process(['php', base_path('scripts/publish-article.php'), $path, '--check']);
    $process->run();

    unlink($path);

    return $process;
}

it('drops the hint --init leaves behind on a quoted value', function (): void {
    // The skeleton written by --init carries its help in a trailing
    // comment. An author who rewrites the title between the quotes and
    // leaves the hint would otherwise submit the hint with it.
    $process = checkArticleFile(<<<'MD'
        ---
        title: "CapNormalize 1.3.0"  # complétez : ce que la version apporte
        summary: "Deux phrases au plus, elles servent de chapeau dans le fil."
        type: release
        version: "1.3.0"  # à confirmer
        ---

        Le texte de l'annonce.
        MD);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Titre   : CapNormalize 1.3.0')
        ->and($process->getOutput())->toContain('Type    : release 1.3.0')
        ->and($process->getOutput())->not->toContain('complétez');
});

it('keeps an apostrophe inside a single quoted value', function (): void {
    $process = checkArticleFile(<<<'MD'
        ---
        title: 'L'import des factures fournisseur'
        summary: "Deux phrases au plus, elles servent de chapeau dans le fil."
        type: announcement
        ---

        Le texte de l'annonce.
        MD);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Titre   : L\'import des factures fournisseur');
});
