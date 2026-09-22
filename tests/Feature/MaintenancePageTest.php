<?php

declare(strict_types=1);

use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;

/**
 * The maintenance page shown while the service is updated.
 *
 * It is prerendered by `artisan down --render` and served by
 * public/index.php BEFORE the Composer autoloader, on a tree whose
 * vendor/ and public/build are being rewritten. Everything it needs
 * must therefore be inside it: an external stylesheet, font or image
 * would be fetched while being replaced, and would fail exactly when
 * the page matters. That autonomy is what these tests hold.
 */
function maintenanceHtml(): string
{
    (new RegisterErrorViewPaths)();

    return view('errors::503', ['retryAfter' => 20])->render();
}

it('renders the maintenance page of the service', function (): void {
    $html = maintenanceHtml();

    // Ours, not the framework's: the framework one says "Service
    // Unavailable" and nothing about an update being under way.
    expect($html)->toContain('Mise à jour en cours')
        ->and($html)->toContain('DoliNews');
});

it('says it in French and in English', function (): void {
    // The page is frozen at the instant `down` runs, in whatever locale
    // the command line held: no request can negotiate anything
    // afterwards, so it carries both languages rather than guessing.
    expect(maintenanceHtml())->toContain('Upgrade in progress');
});

it('carries no external asset and no script', function (): void {
    $html = maintenanceHtml();

    expect(preg_match('#\s(src|href)=[\'"]#i', $html))->toBe(0)
        ->and(stripos($html, '<script'))->toBeFalse()
        ->and(stripos($html, '@vite'))->toBeFalse();
});

it('tells the reader nothing was lost', function (): void {
    // Somebody landing here wants to know whether they lost something.
    $html = maintenanceHtml();

    expect($html)->toContain('Rien n\'est perdu')
        ->and($html)->toContain('Nothing is')
        ->and($html)->toContain('20 secondes');
});
