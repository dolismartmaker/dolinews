<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time possession code of a commit address (SPEC 3.2).
 */
class ContributionCode extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[DoliNews] Code de vérification de contribution',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contribution-code',
        );
    }
}
