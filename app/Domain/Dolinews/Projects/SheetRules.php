<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

/**
 * What a project sheet accepts, in one place (SPEC 4.2).
 *
 * The same sheet is written from the account screens, from the API and
 * by the publishing tools: three copies of these rules drifted apart as
 * soon as one of them gained a field, and the API is the copy a
 * third-party integration meets first.
 *
 * The description is Markdown, rendered through the article whitelist
 * (SPEC D5), and bounded: a sheet presents a project, the documentation
 * lives behind the sheet's doc link where its author maintains it.
 */
final class SheetRules
{
    public const NAME_MAX = 150;

    public const SUMMARY_MAX = 255;

    public const LICENSE_MAX = 50;

    /**
     * The reference version of a sheet: the fields the editor writes in
     * its own language.
     *
     * @param  bool  $partial  rules for a partial update, where every field is optional
     * @return array<string, array<int, string>>
     */
    public static function reference(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:'.self::NAME_MAX],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', self::contentLocales())],
            'summary' => [$required, 'string', 'max:'.self::SUMMARY_MAX],
            'description' => ['nullable', 'string', 'max:'.self::descriptionMax()],
            'license' => ['nullable', 'string', 'max:'.self::LICENSE_MAX],
        ];
    }

    /**
     * One language version of a sheet. It carries the texts and nothing
     * else: the links, the licence and the status belong to the sheet
     * itself, which has only one of each (SPEC 4.2).
     *
     * @return array<string, array<int, string>>
     */
    public static function translation(): array
    {
        return [
            'locale' => ['required', 'string', 'in:'.implode(',', self::contentLocales())],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'summary' => ['required', 'string', 'max:'.self::SUMMARY_MAX],
            'description' => ['nullable', 'string', 'max:'.self::descriptionMax()],
        ];
    }

    /**
     * How long a description may be. Read from the configuration rather
     * than frozen here: a deployment that is not ours may want another
     * bound, and the tools read the same value to refuse early.
     */
    public static function descriptionMax(): int
    {
        return (int) config('dolinews.projects.description_max', 8000);
    }

    /**
     * @return array<int, string>
     */
    private static function contentLocales(): array
    {
        /** @var array<int, string> $locales */
        $locales = (array) config('dolinews.content_locales', ['fr_FR']);

        return $locales;
    }
}
