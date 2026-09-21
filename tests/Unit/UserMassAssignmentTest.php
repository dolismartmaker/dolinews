<?php

declare(strict_types=1);

use App\Models\User;

it('refuses to fill a privilege column from an attribute bag', function (string $column, mixed $value): void {
    $user = new User;
    $user->fill(['name' => 'Candidat', $column => $value]);

    expect($user->getAttribute($column))->toBeNull();
})->with([
    ['is_moderator', true],
    ['is_super_admin', true],
    ['active', false],
    ['feed_token', 'volé'],
]);

it('still fills the profile fields an account writes about itself', function (): void {
    $user = new User;
    $user->fill([
        'name' => 'Candidat',
        'display_name' => 'Cand',
        'bio' => 'Contributeur',
        'website' => 'https://exemple.test',
    ]);

    expect($user->name)->toBe('Candidat')
        ->and($user->display_name)->toBe('Cand')
        ->and($user->website)->toBe('https://exemple.test');
});
