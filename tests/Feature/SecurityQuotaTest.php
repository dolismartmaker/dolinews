<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\PublicationQuotaService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Enums\ArticleStatus;
use Tests\Support\Factory;

/**
 * Security announcements against the publication limits (SPEC 5.3,
 * amended).
 *
 * The limits are checked at submission, so refusing a security
 * announcement keeps it out of the review queue entirely: nobody reads
 * it, nothing is published, and the security emails of SPEC 6.4 never
 * leave. A mechanism meant to protect the volunteers' time would end up
 * suppressing the alert D11 calls the only channel reaching an
 * integrator. So it is never refused at the door.
 */
it('lets an editor announce four fixes in a day on the same module', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    // Bucket capacity is three per project: the fourth ordinary release
    // of the day would be refused, and for a week.
    for ($i = 0; $i < 4; $i++) {
        $fix = Factory::article($author, [
            'title' => 'Module XY 2.'.$i,
            'focus' => 'security',
        ]);

        $articles->submit($fix, $author);

        expect($fix->fresh()?->status)->toBe(ArticleStatus::PENDING);
    }
});

it('refuses the same burst when it is not about security', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    for ($i = 0; $i < 3; $i++) {
        $articles->submit(Factory::article($author, ['title' => 'Version 1.'.$i]), $author);
    }

    // The exemption is the security focus and nothing else: the ordinary
    // pacing still holds, or the limit would have no meaning left.
    expect(fn () => $articles->submit(Factory::article($author, ['title' => 'Version 1.4']), $author))
        ->toThrow(QuotaException::class);
});

it('never charges a security announcement a token', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    $before = app(PublicationQuotaService::class)
        ->availableTokensFor(Factory::article($author, ['title' => 'Sonde']));

    foreach (range(1, 3) as $i) {
        $articles->submit(Factory::article($author, [
            'title' => 'Correctif '.$i,
            'focus' => 'security',
        ]), $author);
    }

    // Charging them would punish the editor for having warned: four
    // fixes in a day would close the bucket for weeks of ordinary
    // releases.
    $after = app(PublicationQuotaService::class)
        ->availableTokensFor(Factory::article($author, ['title' => 'Sonde 2']));

    expect($after)->toBe($before);
});

it('charges the token back when the focus is corrected', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    $quota = app(PublicationQuotaService::class);
    $probe = Factory::article($author, ['title' => 'Sonde']);
    $before = $quota->availableTokensFor($probe);

    $article = Factory::article($author, ['title' => 'Faux correctif', 'focus' => 'security']);
    $articles->submit($article, $author);

    expect($quota->availableTokensFor($probe))->toBe($before);

    // The bucket reads the CURRENT focus: an announcement reclassified
    // after a review request owes its token retroactively, which is the
    // automatic half of the sanction for a misused focus (SPEC 9.3).
    $article->forceFill(['focus' => 'feature_minor'])->save();

    expect($quota->availableTokensFor($probe))->toBe($before - 1);
});

it('journals the exemption, so the abuse can be evidenced', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    for ($i = 0; $i < 3; $i++) {
        $articles->submit(Factory::article($author, ['title' => 'Version 2.'.$i]), $author);
    }

    // The bucket is empty: this one only passes because of the focus.
    $articles->submit(
        Factory::article($author, ['title' => 'Correctif urgent', 'focus' => 'security']),
        $author,
    );

    expect(AuditEntry::query()->where('action', 'article.security_quota_override')->count())->toBe(1);
});

it('says nothing when the exemption was not needed', function (): void {
    $author = Factory::contributorWithoutEditor();

    app(ArticleService::class)->submit(
        Factory::article($author, ['title' => 'Correctif tranquille', 'focus' => 'security']),
        $author,
    );

    // The bucket was full: no limit was stepped aside, so there is
    // nothing to journal and nothing to hold against anyone.
    expect(AuditEntry::query()->where('action', 'article.security_quota_override')->count())->toBe(0);
});

it('lets a security announcement through a full review queue', function (): void {
    $author = Factory::contributorWithoutEditor();
    $articles = app(ArticleService::class);

    // The queue ceiling is five announcements per editor; translations
    // and resubmissions aside, the sixth is refused.
    for ($i = 0; $i < 5; $i++) {
        $pending = Factory::article($author, ['title' => 'En revue '.$i]);
        $pending->forceFill([
            'status' => ArticleStatus::PENDING->value,
            'submitted_at' => now(),
        ])->save();
    }

    $urgent = Factory::article($author, ['title' => 'Correctif pressé', 'focus' => 'security']);

    $articles->submit($urgent, $author);

    expect($urgent->fresh()?->status)->toBe(ArticleStatus::PENDING);
});
