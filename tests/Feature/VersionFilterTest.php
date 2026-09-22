<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\Factory;

/**
 * The version filter asks about ANNOUNCEMENTS, never about modules
 * (SPEC 6.1, D1) - and it can only ask about the majors it offers.
 *
 * Derived from the calendar alone, the list stopped at the last
 * released major while announcements declared ceilings beyond it: a
 * reader saw "Dolibarr 18 à 24" on a card and had no way to ask the
 * feed for 24.
 */
it('offers the majors an announcement declares beyond the calendar', function (): void {
    $ahead = now()->year - 2004 + 2;

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module en avance 1.0',
        'dolibarr_min' => 18,
        'dolibarr_max' => $ahead,
    ]);

    $this->get('/fr')
        ->assertOk()
        ->assertSee('<option value="'.$ahead.'"', escape: false);
});

it('keeps the eight released majors when nothing declares further', function (): void {
    $released = now()->year - 2004;

    $this->get('/fr')
        ->assertOk()
        ->assertSee('<option value="'.$released.'"', escape: false)
        ->assertSee('<option value="'.($released - 7).'"', escape: false)
        ->assertDontSee('<option value="'.($released + 1).'"', escape: false);
});

it('never stretches the list on an absurd ceiling', function (): void {
    // A ceiling is typed by hand and validated against nothing.
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module optimiste 1.0',
        'dolibarr_min' => 18,
        'dolibarr_max' => 99,
    ]);

    $this->get('/fr')
        ->assertOk()
        ->assertDontSee('<option value="99"', escape: false)
        ->assertSee('<option value="'.(now()->year - 2004 + 5).'"', escape: false);
});

it('answers the filter for a major only an announcement knows', function (): void {
    $ahead = now()->year - 2004 + 2;

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module en avance 1.0',
        'dolibarr_min' => 18,
        'dolibarr_max' => $ahead,
    ]);

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module ancien 1.0',
        'dolibarr_min' => 12,
        'dolibarr_max' => 16,
    ]);

    $this->get('/fr?dolibarr='.$ahead)
        ->assertOk()
        ->assertSee('Module en avance 1.0')
        ->assertDontSee('Module ancien 1.0');
});
