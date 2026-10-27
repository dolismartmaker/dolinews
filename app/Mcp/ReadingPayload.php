<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Dolinews\Attestations\AttestationService;
use App\Domain\Dolinews\Feeds\JsonFeedExtension;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectTranslation;
use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Domain\Dolinews\Seo\PageLocale;
use App\Domain\Dolinews\Support\LanguageLabel;
use Closure;

/**
 * The shapes the MCP read server returns, and the language they are
 * written in (SPEC 6.7).
 *
 * A reading surface, not the API: one entry per announcement, the
 * labels already written, the announced floor without the module
 * builder's default. The labels are those of the JSON feed extension
 * (SPEC 6.4), so a client relaying an announcement reads the same
 * wording a third-party site shows - "Dolibarr 18 to 24", a maturity
 * with its age - rather than raw bounds it would be tempted to turn
 * into "compatible with".
 */
final class ReadingPayload
{
    /**
     * Content licence of everything published (SPEC D15), repeated in
     * every result: a quote that names neither the editor nor the
     * licence is not covered by it.
     */
    public const LICENSE = 'CC BY-SA 4.0';

    /**
     * What every result reminds its reader of (D1). The server
     * instructions say it once; a client that keeps only the last result
     * in view must still find it there.
     */
    public const NOTICE = 'DoliNews records what editors announced, and when. It never states the current state of a module: give the announcement date and say "announced".';

    /**
     * Language used when the caller names none. The server instructions
     * are in English, and a client that does not pass the reader's
     * language most likely reads English best.
     */
    private const DEFAULT_LOCALE = 'en';

    /**
     * Interface languages of the service, short form (fr, en, ...).
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_values(array_map('strval', (array) config('dolinews.locales', ['fr'])));
    }

    /**
     * The short interface language a request asks for: "es", "es_ES" and
     * "es-ES" all read as "es". Anything the service does not speak falls
     * back to the default rather than failing the call: the answer is
     * still useful in English.
     */
    public static function locale(mixed $requested): string
    {
        $locales = self::locales();

        if (is_string($requested) && $requested !== '') {
            $short = strtolower(substr(trim($requested), 0, 2));

            if (in_array($short, $locales, true)) {
                return $short;
            }
        }

        return in_array(self::DEFAULT_LOCALE, $locales, true) ? self::DEFAULT_LOCALE : ($locales[0] ?? 'fr');
    }

    /**
     * Run a read under the reader's interface language, so that every
     * label comes out in it, then restore the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function under(string $locale, Closure $read): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $read();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * A tool result: its data, the licence and the reminder of D1.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function envelope(array $data): array
    {
        return $data + [
            'license' => self::LICENSE,
            'notice' => self::NOTICE,
        ];
    }

    /**
     * One announcement in a list.
     *
     * announced_on is the publication date, which is the date of the
     * version announced (SPEC 5.1): the fact the reader needs next to
     * any range or maturity, since the service says what was announced
     * and when, never the current state of a module (D1).
     *
     * @return array<string, mixed>
     */
    public static function announcement(Article $article, string $locale): array
    {
        return [
            'id' => $article->getKey(),
            'title' => $article->title,
            'summary' => $article->summary,
            'announced_on' => $article->published_at?->format('Y-m-d'),
            'url' => ArticleUrl::for($article),
        ] + JsonFeedExtension::for($article, $locale);
    }

    /**
     * One project sheet in a list, its summary in the reader's language
     * when the sheet has a version in it, and the language said
     * otherwise (SPEC 6.1). No compatibility of any kind: the sheet is
     * persistent, so it carries nothing that would go stale (D1).
     *
     * @return array<string, mixed>
     */
    public static function project(Project $project, string $locale): array
    {
        $translation = self::sheetTranslation($project, $locale);

        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'summary' => $translation->summary ?? $project->summary,
            'locale' => $translation->locale ?? $project->locale,
            'language' => $translation === null ? LanguageLabel::foreign($project->locale, $locale) : null,
            // What the editor says of its project, not a measure.
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'editor' => $project->editor === null ? null : [
                'slug' => $project->editor->slug,
                'name' => $project->editor->name,
            ],
            'url' => self::projectUrl($project, $locale),
        ];
    }

    /**
     * The version of a sheet written in the reader's language, if any.
     */
    public static function sheetTranslation(Project $project, string $locale): ?ProjectTranslation
    {
        /** @var ProjectTranslation|null $translation */
        $translation = $project->translations->firstWhere('locale', PageLocale::full($locale));

        return $translation;
    }

    /**
     * One test indicator (SPEC 10): declared by the editor, dated, and
     * pointing at its report. The same shape whatever its source, so
     * that no tool reads better tested than another.
     *
     * @return array<string, mixed>
     */
    public static function attestation(Attestation $attestation): array
    {
        return [
            'label' => __('Tests publiés par l\'éditeur'),
            'declared_by_editor' => true,
            'metric' => $attestation->metric,
            'value' => $attestation->value,
            'unit' => $attestation->unit,
            'measured_on' => $attestation->measured_at?->format('Y-m-d'),
            'freshness' => app(AttestationService::class)->freshness($attestation),
            'report_url' => $attestation->source_url,
            'report_link_broken' => $attestation->is_broken,
        ];
    }

    /**
     * Public address of a sheet, under the language being read.
     */
    public static function projectUrl(Project $project, string $locale): string
    {
        return route('projects.show', ['locale' => $locale, 'slug' => $project->slug]);
    }

    /**
     * Public address of an editor page, under the language being read.
     */
    public static function editorUrl(Editor $editor, string $locale): string
    {
        return route('editors.show', ['locale' => $locale, 'slug' => $editor->slug]);
    }
}
