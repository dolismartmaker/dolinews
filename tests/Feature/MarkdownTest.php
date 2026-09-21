<?php

declare(strict_types=1);

use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Whitelist rendering of article bodies (SPEC D5/D7/D8).
 */
it('renders markdown and strips raw html', function (): void {
    $html = app(ArticleMarkdown::class)->render(
        "Bonjour <script>alert('xss')</script>\n\n**Version** 2.1 disponible."
    );

    // The tag is stripped; the payload text stays as inert text.
    expect($html)->toContain('<strong>Version</strong>')
        ->and($html)->not->toContain('<script');
});

it('marks every outgoing link nofollow ugc', function (): void {
    $html = app(ArticleMarkdown::class)->render('Voir [la fiche](https://example.com/pricing).');

    expect($html)->toContain('rel="nofollow ugc"');
});

it('drops images outside the media disk', function (): void {
    Storage::fake(Media::DISK);

    config()->set('app.url', 'http://localhost');

    $external = app(ArticleMarkdown::class)->render('![hotlink](https://evil.example/tracking.png)');
    expect($external)->not->toContain('<img');

    $local = app(ArticleMarkdown::class)->render(
        '![capture]('.Storage::disk(Media::DISK)->url('1/ab/hash.png').')'
    );
    expect($local)->toContain('<img');
});

it('keeps only whitelisted tags', function (): void {
    $html = app(ArticleMarkdown::class)->render('Avant <iframe src="https://evil.example"></iframe> apres');

    expect($html)->not->toContain('<iframe')
        ->and($html)->toContain('Avant')
        ->and($html)->toContain('apres');
});
