<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ArticleSubmitted;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\Support\Factory;

/**
 * Mails built from the framework's notification template, and the two
 * account mails the framework sends itself.
 *
 * Their strings were English keys ("Hello!", "Regards,", "Verify Email
 * Address"): the service's source strings are French and French has no
 * language file, so nothing translated them, and a French reader read
 * English.
 */
function renderedMail(MailMessage $mail): string
{
    return (string) $mail->render();
}

it('greets and signs a review mail in the language of its recipient', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);

    app()->setLocale('fr');
    $french = renderedMail((new ArticleSubmitted($article, $author))->toMail($author));

    expect($french)->toContain('Bonjour,')
        ->and($french)->toContain('Cordialement,')
        ->and($french)->toContain('ne fonctionne pas, copiez cette adresse')
        ->and($french)->not->toContain('Hello!')
        ->and($french)->not->toContain('Regards')
        ->and($french)->not->toContain('having trouble');

    app()->setLocale('en');
    $english = renderedMail((new ArticleSubmitted($article, $author))->toMail($author));

    expect($english)->toContain('Kind regards,')
        ->and($english)->toContain('copy this address into your browser')
        ->and($english)->not->toContain('Bonjour,');
});

it('asks to confirm an address in French', function (): void {
    $user = User::factory()->unverified()->create();
    app()->setLocale('fr');

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->subject)->toBe('[DoliNews] Confirmez votre adresse électronique')
        ->and(renderedMail($mail))->toContain('Confirmer mon adresse')
        ->and(renderedMail($mail))->not->toContain('Verify Email Address');
});

it('sends a password reset link in French, with its delay', function (): void {
    $user = User::factory()->create();
    app()->setLocale('fr');

    $mail = (new ResetPassword('jeton-test'))->toMail($user);
    $html = renderedMail($mail);

    expect($mail->subject)->toBe('[DoliNews] Réinitialisation du mot de passe')
        ->and($html)->toContain('Réinitialiser le mot de passe')
        ->and($html)->toContain('Ce lien expire dans 60 minutes.')
        ->and($html)->toContain('jeton-test')
        ->and($html)->not->toContain('Reset Password Notification');
});
