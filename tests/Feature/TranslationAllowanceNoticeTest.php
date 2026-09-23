<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\TranslationUsage;
use App\Domain\Dolinews\Translation\AutoTranslationException;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Notifications\TranslationAllowanceSpent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * What an editor is told when the shared monthly allowance runs out
 * (SPEC 5.7).
 *
 * The overrun is already stated on the editor's screen. A screen
 * reaches whoever opens it, and an editor whose Spanish versions
 * stopped appearing has no reason to go and look: the mail is what
 * closes that gap, and it names the two ways out - its own DeepL key,
 * or depositing translations through the API.
 *
 * The bound that matters is the frequency: the allowance is met again
 * by every announcement published for the rest of the month, so a
 * notice per refusal would be a mail per announcement.
 */
function spentAllowance(Editor $editor): TranslationUsage
{
    /** @var TranslationUsage $usage */
    $usage = TranslationUsage::query()->create([
        'editor_id' => $editor->getKey(),
        'period' => app(TranslationRouter::class)->period(),
        'characters' => app(TranslationRouter::class)->ceiling(),
    ]);

    return $usage;
}

/**
 * An engine that is available but never reached: what is under test is
 * the refusal, before any call.
 */
function idleEngine(): void
{
    app()->instance(TranslationEngine::class, new class implements TranslationEngine
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            return null;
        }

        public function supportedLocales(): array
        {
            return [];
        }
    });
}

/**
 * The announcement, with its editor read again.
 *
 * The factory publishes BEFORE machine translation is enabled, on
 * purpose: enabled first, the publication itself would translate the
 * announcement and there would be nothing left to test. The relation
 * loaded at that moment still holds auto_translate = false, so what is
 * passed to the service has to be read again.
 */
function freshSource(Article $source): Article
{
    /** @var Article $fresh */
    $fresh = Article::query()->with('editor')->findOrFail($source->getKey());

    return $fresh;
}

it('warns the editor when the automatic path meets the allowance', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->save();

    spentAllowance($editor);

    expect(app(AutoTranslationService::class)->sync(freshSource($source)))->toBe(0);

    Notification::assertSentTo($author, TranslationAllowanceSpent::class);
});

it('warns once per period, whatever the number of announcements', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $first = Factory::publishedArticle($author, ['title' => 'Première annonce']);
    $second = Factory::publishedArticle($author, ['title' => 'Deuxième annonce']);
    $editor->auto_translate = true;
    $editor->save();

    spentAllowance($editor);

    $translations = app(AutoTranslationService::class);
    $translations->sync(freshSource($first));
    $translations->sync(freshSource($second));

    // A mail per announcement is how an editor stops reading them, and
    // the service needs that address the day a security announcement
    // waits in review.
    Notification::assertSentToTimes($author, TranslationAllowanceSpent::class, 1);
});

it('writes to the owner of the editor, in the language of that account', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->save();

    $author->locale = 'de';
    $author->save();

    spentAllowance($editor);

    app(AutoTranslationService::class)->sync(freshSource($source));

    // preferredLocale() is what Laravel reads to render the mail: the
    // interface locale lives in a session no queued mail can reach.
    Notification::assertSentTo(
        $author,
        TranslationAllowanceSpent::class,
        static fn ($notification, $channels, $notifiable): bool => $notifiable->preferredLocale() === 'de',
    );
});

it('names the two ways out and the renewal date', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $editor->auto_translate = true;
    $editor->save();

    $usage = spentAllowance($editor);

    $notice = new TranslationAllowanceSpent(
        $editor,
        $usage->characters,
        app(TranslationRouter::class)->ceiling(),
        now()->addMonthNoOverflow()->startOfMonth(),
    );

    $rendered = (string) $notice->toMail($author)->render();

    expect($rendered)->toContain('DeepL')
        ->and($rendered)->toContain(route('account.translations.automatic'))
        ->and($rendered)->toContain(route('pages.api'))
        // A state, never a breakage: the announcements stay published.
        ->and($rendered)->toContain('restent publiées')
        // Both buttons actually drawn: an unknown colour renders one of
        // them white on white, href and all, which no assertion on the
        // address would have caught.
        ->and(substr_count($rendered, 'class="button button-primary"'))->toBe(2);
});

it('never names the engine the service translates on', function (): void {
    // The repository is public and no interface of the service says it
    // (SPEC 5.7). The editor's own supplier is another matter.
    config()->set('dolinews.translation.endpoint', 'https://moteur-interne.invalid/v2');

    [$author, $editor] = Factory::contributorWithEditor();
    $usage = spentAllowance($editor);

    $rendered = (string) (new TranslationAllowanceSpent(
        $editor,
        $usage->characters,
        app(TranslationRouter::class)->ceiling(),
        now()->addMonthNoOverflow()->startOfMonth(),
    ))->toMail($author)->render();

    expect($rendered)->not->toContain('moteur-interne.invalid');
});

it('leaves an editor on its own key alone', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->translation_api_key = 'cle-de-l-editeur';
    $editor->save();

    spentAllowance($editor);

    app(AutoTranslationService::class)->sync(freshSource($source));

    // Its own supplier, its own bill: there is no allowance of ours to
    // tell it about.
    Notification::assertNotSentTo($author, TranslationAllowanceSpent::class);
});

it('says nothing when the allowance is not what refused', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);

    // auto_translate off: the refusal is the missing opt-in, and a mail
    // about an allowance would be beside the point.
    app(AutoTranslationService::class)->sync(freshSource($source));

    Notification::assertNotSentTo($author, TranslationAllowanceSpent::class);
});

it('warns on the single-announcement button too', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->save();

    spentAllowance($editor);

    expect(fn () => app(AutoTranslationService::class)->translateInto(freshSource($source), 'es_ES'))
        ->toThrow(AutoTranslationException::class);

    Notification::assertSentTo($author, TranslationAllowanceSpent::class);
});

it('sends nothing from a dry run of the command', function (): void {
    Notification::fake();
    idleEngine();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->save();

    spentAllowance($editor);

    // A dry run promises to write nothing, and a mail is a write.
    $this->artisan('dolinews:translate', [
        '--article' => $source->getKey(),
        '--dry-run' => true,
    ])->assertSuccessful();

    Notification::assertNotSentTo($author, TranslationAllowanceSpent::class);
});

it('sends nothing when --force spends past the allowance', function (): void {
    Notification::fake();

    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author);
    $editor->auto_translate = true;
    $editor->save();

    spentAllowance($editor);

    app()->instance(TranslationEngine::class, new class implements TranslationEngine
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            return array_map(static fn (string $text): string => '[es] '.$text, array_values($texts));
        }

        public function supportedLocales(): array
        {
            return [];
        }
    });

    $this->artisan('dolinews:translate', [
        '--article' => $source->getKey(),
        '--locale' => ['es_ES'],
        '--force' => true,
    ])->assertSuccessful();

    // Nothing was refused, so there is nothing to tell the editor: the
    // operator decided to spend past the ceiling and the versions came
    // out.
    Notification::assertNotSentTo($author, TranslationAllowanceSpent::class);
});
