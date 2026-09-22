<?php

declare(strict_types=1);

/**
 * Interface locales (SPEC D14).
 *
 * French is the source language: its strings are the translation keys,
 * so it has no file. Every other offered locale must cover the whole
 * key set, because Laravel silently falls back to English on a missing
 * key: a half-translated file produces a bilingual page nobody notices.
 */
it('offers every configured locale with an endonym', function (): void {
    $locales = (array) config('dolinews.locales');
    $names = (array) config('dolinews.locale_names');

    expect($locales)->toContain('fr')->toContain('en');

    foreach ($locales as $locale) {
        expect($names)->toHaveKey($locale);
        expect($names[$locale])->toBeString()->not->toBe('');
    }
});

it('translates every key in every offered locale', function (): void {
    $reference = json_decode((string) file_get_contents(lang_path('en.json')), true);

    expect($reference)->toBeArray()->not->toBeEmpty();

    $referenceKeys = array_keys($reference);

    foreach ((array) config('dolinews.locales') as $locale) {
        // The source language carries no file: the keys are French.
        if ($locale === 'fr') {
            continue;
        }

        $path = lang_path($locale.'.json');

        expect(file_exists($path))->toBeTrue("Fichier de langue manquant : lang/{$locale}.json");

        $translations = json_decode((string) file_get_contents($path), true);

        expect($translations)->toBeArray("JSON invalide dans lang/{$locale}.json");

        $missing = array_diff($referenceKeys, array_keys($translations));
        $extra = array_diff(array_keys($translations), $referenceKeys);

        expect($missing)->toBe([], sprintf(
            'lang/%s.json : %d cles non traduites, la page sortirait en anglais. Premiere : %s',
            $locale,
            count($missing),
            (string) (reset($missing) ?: ''),
        ));

        // An extra key is a key that no longer exists, or a typo in the
        // French source: both go unnoticed without this check.
        expect($extra)->toBe([], sprintf(
            'lang/%s.json : %d cles inconnues du francais. Premiere : %s',
            $locale,
            count($extra),
            (string) (reset($extra) ?: ''),
        ));

        foreach ($translations as $key => $value) {
            expect($value)->toBeString("lang/{$locale}.json : la valeur de \"{$key}\" n'est pas une chaine");
        }
    }
});

it('accepts a content locale for every interface locale', function (): void {
    // A reader who browses the service in their language must be able to
    // submit in it (SPEC D14): the two lists stay aligned.
    $content = (array) config('dolinews.content_locales');
    $prefixes = array_map(
        static fn (string $locale): string => substr($locale, 0, 2),
        $content,
    );

    foreach ((array) config('dolinews.locales') as $locale) {
        // in_array rather than toContain: the second argument of
        // toContain is another expected value, not a message.
        expect(in_array($locale, $prefixes, true))->toBeTrue(
            "Aucune locale de contenu pour la langue d'interface {$locale}",
        );
    }
});

it('serves the home page in every offered locale', function (): void {
    foreach ((array) config('dolinews.locales') as $locale) {
        $this->from('/')->get('/locale/'.$locale)->assertRedirect('/');

        $this->get('/')->assertOk()
            ->assertSee(config('dolinews.locale_names.'.$locale), escape: false)
            // The document language follows the choice, for screen
            // readers and for search engines.
            ->assertSee('lang="'.$locale.'"', escape: false);
    }
});

it('holds a translation for every string the code asks for', function (): void {
    // The check above compares the language files with each other, which
    // says nothing about a string the code passes to __() with no key
    // anywhere: Laravel then prints the French key, in silence, on an
    // otherwise Spanish page. That is how 257 strings stayed untranslated
    // while the suite was green.
    $reference = json_decode((string) file_get_contents(lang_path('en.json')), true);

    expect($reference)->toBeArray();

    $keys = array_keys((array) $reference);
    $missing = [];

    $roots = [app_path(), resource_path('views'), base_path('routes'), base_path('config'), base_path('bootstrap')];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Literal arguments only: __($variable) cannot be checked
            // here, and neither can a concatenation.
            preg_match_all('/__\(\s*(\'((?:\\\\.|[^\'])*)\'|"((?:\\\\.|[^"])*)")/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $raw = $match[2] ?? $match[3] ?? '';
                $string = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $raw);

                if ($string !== '' && ! in_array($string, $keys, true)) {
                    $missing[$string] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }
    }

    expect($missing)->toBe([], sprintf(
        '%d chaine(s) passee(s) a __() sans cle de traduction. Premiere : "%s" dans %s',
        count($missing),
        (string) (array_key_first($missing) ?? ''),
        (string) (reset($missing) ?: ''),
    ));
});

it('translates the feed page beyond the navigation', function (): void {
    $this->from('/')->get('/locale/de')->assertRedirect('/');

    // A string from the page body, not from the menu: a locale wired up
    // in config but with no file would still show its endonym.
    $this->get('/')->assertOk()->assertSee('Ankündigungen aus dem Dolibarr-Ökosystem');

    $this->from('/')->get('/locale/el')->assertRedirect('/');

    $this->get('/')->assertOk()->assertSee('Ανακοινώσεις του οικοσυστήματος Dolibarr');
});
