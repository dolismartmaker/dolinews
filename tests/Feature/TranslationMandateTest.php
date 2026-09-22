<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\TranslationMandate;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

/**
 * Translation mandates and the publication regime of language versions
 * (SPEC 5.1/5.6, settled 2026-09-22).
 *
 * Without a mandate, translating is reserved to the editor, which leaves
 * an ecosystem of ten languages to be served by the people least likely
 * to speak them. With one, an outside translator submits under the
 * editor's name, and a single reviewer publishes: the substance was
 * reviewed on the source, only fidelity is left.
 */

/**
 * @return array{0: User, 1: Article}
 */
function mandateSource(): array
{
    [$author] = Factory::contributorWithEditor();

    return [$author, Factory::publishedArticle($author, [
        'title' => 'Module mandate 1.0',
        'locale' => 'fr_FR',
    ])];
}

function submitTranslationAs(User $translator, Article $source, string $locale = 'es_ES'): Article
{
    $translation = app(TranslationService::class)->submitTranslation($source->refresh(), $translator, $locale, [
        'title' => 'Version traduite',
        'summary' => 'Resume traduit.',
        'body' => '## Corps traduit',
    ]);

    return app(ArticleService::class)->submit($translation, $translator);
}

it('refuses a translation from an account the editor never mandated', function (): void {
    [, $source] = mandateSource();

    submitTranslationAs(Factory::contributorWithoutEditor(), $source);
})->throws(ArticleException::class);

it('lets a mandated account submit a translation', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator);

    $translation = submitTranslationAs($translator, $source);

    // It goes through review: the account writing is not the one the
    // announcement belongs to.
    expect($translation->refresh()->status)->toBe(ArticleStatus::PENDING)
        ->and($translation->editor_id)->toBe($source->editor_id)
        ->and($translation->author_user_id)->toBe($translator->getKey());
});

it('publishes a mandated translation on a single reviewer', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator);

    $translation = submitTranslationAs($translator, $source);

    app(ReviewService::class)->postMessage(
        $translation,
        User::factory()->moderator()->create(),
        'fidele a la source',
        ReviewDecision::ACCEPTED,
    );

    expect($translation->refresh()->status)->toBe(ArticleStatus::PUBLISHED);
});

it('publishes a translation at the date of its announcement', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator);

    // Three weeks later: the release date is the source's, not the
    // translator's, or a back-dated catalogue would come back to the top
    // of the feed one language at a time (SPEC 5.1/6.4).
    $this->travel(21)->days();

    $translation = submitTranslationAs($translator, $source);

    app(ReviewService::class)->postMessage(
        $translation,
        User::factory()->moderator()->create(),
        'fidele',
        ReviewDecision::ACCEPTED,
    );

    expect($translation->refresh()->published_at?->format('Y-m-d H:i:s'))
        ->toBe($source->refresh()->published_at?->format('Y-m-d H:i:s'));
});

it('publishes without review the translation an editor writes itself', function (): void {
    [$author, $source] = mandateSource();

    $translation = submitTranslationAs($author, $source);

    // The editor answers for what goes out under its name, and the text
    // it translates was reviewed (SPEC 5.1).
    expect($translation->refresh()->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($translation->publication_mode)->toBe(PublicationMode::TRANSLATION)
        ->and($translation->published_at?->format('Y-m-d H:i:s'))
        ->toBe($source->refresh()->published_at?->format('Y-m-d H:i:s'));
});

it('leaves an editor translation with its source when both are in review', function (): void {
    [$author] = Factory::contributorWithEditor();

    $source = Factory::article($author, ['title' => 'Module en revue 2.0', 'locale' => 'fr_FR']);
    app(ArticleService::class)->submit($source, $author);

    $translation = submitTranslationAs($author, $source, 'en_US');

    // The source is not out yet: publishing its Greek version would
    // announce what nobody announced.
    expect($translation->refresh()->status)->toBe(ArticleStatus::PENDING);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($source, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    // The nominal case: the release leaves review, its language versions
    // leave with it.
    expect($translation->refresh()->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($translation->published_at?->format('Y-m-d H:i:s'))
        ->toBe($source->refresh()->published_at?->format('Y-m-d H:i:s'));
});

it('holds a mandate to the languages it names', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator, null, ['es_ES']);

    $spanish = submitTranslationAs($translator, $source, 'es_ES');
    expect($spanish->refresh()->status)->toBe(ArticleStatus::PENDING);

    submitTranslationAs($translator, $source, 'el_GR');
})->throws(ArticleException::class);

it('holds a project-scoped mandate to its own sheet', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    // A mandate on another sheet of the same editor covers nothing here.
    $other = Project::query()->create([
        'editor_id' => $source->editor_id,
        'slug' => 'autre-fiche',
        'name' => 'Autre fiche',
        'summary' => 'Autre',
        'status' => 'active',
    ]);

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator, $other);

    submitTranslationAs($translator, $source);
})->throws(ArticleException::class);

it('stops a revoked mandate from translating', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    $mandate = app(TranslationMandateService::class)->grant($source->editor, $author, $translator);
    app(TranslationMandateService::class)->revoke($mandate, $author);

    // The row stays readable: an editor sees who held what and when.
    expect($mandate->refresh()->revoked_at)->not->toBeNull()
        ->and($mandate->revoked_by_user_id)->toBe($author->getKey());

    submitTranslationAs($translator, $source);
})->throws(ArticleException::class);

it('grants a mandate to contributor accounts only', function (): void {
    [$author, $source] = mandateSource();

    // Translating is writing: the proof of contribution is required
    // here as anywhere else (SPEC 3.1).
    app(TranslationMandateService::class)->grant($source->editor, $author, User::factory()->create());
})->throws(ArticleException::class);

it('lets only the owner grant a mandate', function (): void {
    [$author, $source] = mandateSource();
    $member = Factory::contributorWithoutEditor();

    app(EditorService::class)->attachMember($source->editor, $author, $member);

    app(TranslationMandateService::class)->grant(
        $source->editor,
        $member,
        Factory::contributorWithoutEditor(),
    );
})->throws(ArticleException::class);

it('refuses a mandate to a member who needs none', function (): void {
    [$author, $source] = mandateSource();
    $member = Factory::contributorWithoutEditor();

    app(EditorService::class)->attachMember($source->editor, $author, $member);

    app(TranslationMandateService::class)->grant($source->editor, $author, $member);
})->throws(ArticleException::class);

it('shows the mandates of an account on its translation screen', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator);

    // The editor sees who it mandated, the translator sees who mandated
    // them: one screen, two sides.
    $this->actingAs($author->refresh())
        ->get('/account/translations/mandats')
        ->assertOk()
        ->assertSee($translator->display_name ?? $translator->name);

    $this->actingAs($translator->refresh())
        ->get('/account/translations/mandats')
        ->assertOk()
        ->assertSee($source->editor->name);
});

it('grants and withdraws a mandate from the account screen', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    $this->actingAs($author->refresh())
        ->post('/account/translations/mandats', [
            'translator_email' => $translator->email,
            'locales' => ['es_ES'],
        ])
        ->assertRedirect();

    /** @var TranslationMandate $mandate */
    $mandate = TranslationMandate::query()->firstOrFail();

    expect($mandate->locales)->toBe(['es_ES'])
        ->and($mandate->editor_id)->toBe($source->editor_id);

    $this->actingAs($author->refresh())
        ->delete('/account/translations/mandats/'.$mandate->getKey())
        ->assertRedirect();

    expect($mandate->refresh()->isInForce())->toBeFalse();
});

it('opens the translation screen to a mandated account', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator);

    // The way in is the announcement page, the only screen a mandated
    // translator has to start from (SPEC 5.6).
    $this->actingAs($translator->refresh())
        ->get('/articles/'.$source->getKey())
        ->assertOk()
        ->assertSee(route('account.articles.translations.create', $source), escape: false);

    $this->actingAs($translator->refresh())
        ->get('/account/articles/'.$source->getKey().'/translations/new')
        ->assertOk()
        ->assertSee($source->title);
});

it('closes the translation screen to an account without a mandate', function (): void {
    [, $source] = mandateSource();
    $stranger = Factory::contributorWithoutEditor();

    $this->actingAs($stranger)
        ->get('/articles/'.$source->getKey())
        ->assertOk()
        ->assertDontSee(route('account.articles.translations.create', $source), escape: false);

    $this->actingAs($stranger)
        ->get('/account/articles/'.$source->getKey().'/translations/new')
        ->assertForbidden();
});

it('submits a translation from the mandated screen', function (): void {
    [$author, $source] = mandateSource();
    $translator = Factory::contributorWithoutEditor();

    app(TranslationMandateService::class)->grant($source->editor, $author, $translator, null, ['es_ES']);

    $this->actingAs($translator->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations', [
            'locale' => 'es_ES',
            'title' => 'Modulo mandato 1.0',
            'summary' => 'Resumen traducido.',
            'body' => '## Cuerpo',
        ])
        ->assertRedirect();

    $translation = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    expect($translation->author_user_id)->toBe($translator->getKey())
        ->and($translation->editor_id)->toBe($source->editor_id);

    // A language the mandate does not name is refused, at the screen as
    // in the service.
    $this->actingAs($translator->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations', [
            'locale' => 'el_GR',
            'title' => 'T',
            'summary' => 'S',
            'body' => 'B',
        ])
        ->assertForbidden();
});

it('switches automatic translation on and off from the account screen', function (): void {
    [$author, $source] = mandateSource();

    config()->set('dolinews.translation.endpoint', 'https://translate.test/api/v1');
    config()->set('dolinews.translation.token', 'jeton');

    $this->actingAs($author->refresh())
        ->get('/account/translations/automatique')
        ->assertOk()
        ->assertSee(__('Traduire les langues manquantes'));

    $this->actingAs($author->refresh())
        ->post('/account/translations/automatique', ['auto_translate' => '1'])
        ->assertRedirect();

    expect($source->editor->refresh()->auto_translate)->toBeTrue();

    $this->actingAs($author->refresh())
        ->post('/account/translations/automatique', ['auto_translate' => '0'])
        ->assertRedirect();

    expect($source->editor->refresh()->auto_translate)->toBeFalse();
});

it('offers the two ways of translating on the entry page', function (): void {
    [$author] = mandateSource();

    config()->set('dolinews.translation.endpoint', 'https://translate.test/api/v1');
    config()->set('dolinews.translation.token', 'jeton');

    // They add up and are never a choice between them (SPEC 5.6/5.7).
    $this->actingAs($author->refresh())
        ->get('/account/translations')
        ->assertOk()
        ->assertSee(__('Confier à une personne'))
        ->assertSee(__('Laisser le service traduire'));
});

it('hides the automatic side when no engine is configured', function (): void {
    [$author] = mandateSource();

    config()->set('dolinews.translation.endpoint', '');
    config()->set('dolinews.translation.token', '');

    // A switch promising a translation nobody will produce is worse
    // than no switch (SPEC 5.7).
    $this->actingAs($author->refresh())
        ->get('/account/translations')
        ->assertOk()
        ->assertSee(__('Cette possibilité n\'est pas ouverte sur ce service pour le moment.'));
});

it('stores and drops the editor own translation key', function (): void {
    [$author, $source] = mandateSource();

    config()->set('dolinews.translation.endpoint', 'https://translate.test/api/v1');
    config()->set('dolinews.translation.token', 'jeton');

    $this->actingAs($author->refresh())
        ->post('/account/translations/automatique/cle', ['translation_api_key' => 'cle-editeur:fx'])
        ->assertRedirect();

    $editor = $source->editor->refresh();

    expect($editor->translation_api_key)->toBe('cle-editeur:fx')
        ->and($editor->translation_key_set_at)->not->toBeNull();

    // Encrypted at rest: a dump of the table hands over nothing.
    $stored = (string) DB::table('editors')->where('id', $editor->getKey())->value('translation_api_key');
    expect($stored)->not->toBe('cle-editeur:fx');

    // Never shown again, only its date.
    $this->actingAs($author->refresh())
        ->get('/account/translations/automatique')
        ->assertOk()
        ->assertDontSee('cle-editeur:fx');

    $this->actingAs($author->refresh())
        ->post('/account/translations/automatique/cle', ['translation_api_key' => ''])
        ->assertRedirect();

    expect($source->editor->refresh()->translation_api_key)->toBeNull();
});
