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

    $medium = Media::query()->create([
        'path' => '1/ab/hash.png',
        'mime' => 'image/png',
        'width' => 90,
        'height' => 60,
        'bytes' => 120,
        'hash' => str_repeat('a', 64),
    ]);

    $local = app(ArticleMarkdown::class)->render(
        '![capture]('.Storage::disk(Media::DISK)->url($medium->path).')'
    );
    expect($local)->toContain('<img');
});

it('keeps illustrating a body written under another host', function (): void {
    // A published body freezes the absolute url of its deposit time. The
    // service moved to https since, and the article must stay
    // illustrated: the match is on the path, and the emitted src is the
    // canonical one.
    Storage::fake(Media::DISK, ['url' => 'https://dolinews.com/storage/media']);

    $medium = Media::query()->create([
        'path' => '7/cd/moved.png',
        'mime' => 'image/png',
        'width' => 90,
        'height' => 60,
        'bytes' => 120,
        'hash' => str_repeat('b', 64),
    ]);

    $html = app(ArticleMarkdown::class)->render(
        '![capture](http://localhost/storage/media/'.$medium->path.')'
    );

    expect($html)->toContain('<img')
        ->and($html)->toContain('https://dolinews.com/storage/media/'.$medium->path)
        ->and($html)->not->toContain('http://localhost');
});

it('refuses a hotlink disguised as a media path', function (): void {
    // Matching on the path alone would let any host serve an image as
    // long as it mimicked the media layout: the src is rewritten to this
    // disk, so the foreign host never survives the render.
    Storage::fake(Media::DISK, ['url' => 'https://dolinews.com/storage/media']);

    $medium = Media::query()->create([
        'path' => '3/ef/legit.png',
        'mime' => 'image/png',
        'width' => 90,
        'height' => 60,
        'bytes' => 120,
        'hash' => str_repeat('c', 64),
    ]);

    $known = app(ArticleMarkdown::class)->render(
        '![capture](https://evil.example/storage/media/'.$medium->path.')'
    );

    expect($known)->toContain('https://dolinews.com/storage/media/'.$medium->path)
        ->and($known)->not->toContain('evil.example');

    // An unknown path under the same layout is dropped outright.
    $unknown = app(ArticleMarkdown::class)->render(
        '![pixel](https://evil.example/storage/media/9/ff/tracking.png)'
    );

    expect($unknown)->not->toContain('<img');
});

it('keeps only whitelisted tags', function (): void {
    $html = app(ArticleMarkdown::class)->render('Avant <iframe src="https://evil.example"></iframe> apres');

    expect($html)->not->toContain('<iframe')
        ->and($html)->toContain('Avant')
        ->and($html)->toContain('apres');
});
