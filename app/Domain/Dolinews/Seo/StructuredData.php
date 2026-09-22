<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectTranslation;

/**
 * Machine-readable description of what a page holds (schema.org).
 *
 * An announcement is a very structured object - an editor, a project, a
 * version, a date, a Dolibarr range - and saying so in the page is what
 * lets a search engine present it as an announcement rather than as a
 * paragraph of text it has to guess at.
 *
 * Two lines are not crossed here. Nothing states the current state of a
 * module, only what was announced and when (SPEC D1); and nothing turns
 * an attestation into a rating, which the service never issues
 * (SPEC 10).
 */
class StructuredData
{
    /**
     * The service itself, with the feed search as an entry point.
     *
     * @return array<string, mixed>
     */
    public function forSite(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'DoliNews',
            'url' => route('home'),
            'inLanguage' => PageLocale::tag(app()->getLocale()),
            // The reader knows a function - "Factur-X", "caisse" - not a
            // slug (SPEC 6.1). The free search is therefore the way in,
            // and it is the one worth exposing.
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('home').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * One published announcement.
     *
     * @return array<string, mixed>
     */
    public function forArticle(Article $article, ?string $imageUrl = null): array
    {
        $editor = $article->editor;
        $project = $article->project;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            // Never shortened, here as everywhere else: a cut title
            // reads as an interrupted sentence (SPEC 5.7).
            'headline' => $article->title,
            'description' => $article->summary,
            'url' => ArticleUrl::for($article),
            'mainEntityOfPage' => ArticleUrl::for($article),
            'datePublished' => $article->published_at?->toAtomString(),
            'dateModified' => ($article->updated_at ?? $article->published_at)?->toAtomString(),
            'inLanguage' => PageLocale::tag($article->locale),
            // Share-alike travels with every copy (SPEC D15), as it does
            // in the JSON feed.
            'license' => (string) config('dolinews.content_license.url'),
            'isAccessibleForFree' => true,
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'DoliNews',
                'url' => route('home'),
            ],
        ];

        $data['author'] = [
            '@type' => 'Organization',
            'name' => $editor->name,
            'url' => route('editors.show', ['slug' => $editor->slug]),
        ];

        if ($project !== null) {
            $data['about'] = [
                '@type' => 'SoftwareApplication',
                'name' => $project->name,
                'url' => route('projects.show', ['slug' => $project->slug]),
                'applicationCategory' => 'BusinessApplication',
            ];

            if ($article->version !== null && $article->version !== '') {
                $data['about']['softwareVersion'] = $article->version;
            }
        }

        $image = PageImage::absolute($imageUrl);

        if ($image !== null) {
            $data['image'] = $image;
        }

        return $data;
    }

    /**
     * A project sheet, in the reader's language when it has one.
     *
     * @return array<string, mixed>
     */
    public function forProject(Project $project, ?ProjectTranslation $translation = null, ?string $imageUrl = null): array
    {
        $editor = $project->editor;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $translation->name ?? $project->name,
            'description' => $translation->summary ?? $project->summary,
            'url' => route('projects.show', ['slug' => $project->slug]),
            'applicationCategory' => 'BusinessApplication',
            // The sheet carries no Dolibarr compatibility, and neither
            // does its machine-readable form: a persistent sheet holding
            // a dated fact becomes false as it ages (SPEC D1).
            'operatingSystem' => 'Dolibarr',
        ];

        if ($project->license !== null && $project->license !== '') {
            $data['license'] = $project->license;
        }

        if ($editor !== null) {
            $data['author'] = [
                '@type' => 'Organization',
                'name' => $editor->name,
                'url' => route('editors.show', ['slug' => $editor->slug]),
            ];
        }

        $image = PageImage::absolute($imageUrl);

        if ($image !== null) {
            $data['image'] = $image;
        }

        return $data;
    }

    /**
     * An editor page.
     *
     * @return array<string, mixed>
     */
    public function forEditor(Editor $editor, ?string $imageUrl = null): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $editor->name,
            'url' => route('editors.show', ['slug' => $editor->slug]),
        ];

        if ($editor->description !== null && $editor->description !== '') {
            $data['description'] = $editor->description;
        }

        // The declared site of the editor, which is also what an outgoing
        // link of the sheet is checked against (SPEC 8).
        if ($editor->website !== null && $editor->website !== '') {
            $data['sameAs'] = [$editor->website];
        }

        $image = PageImage::absolute($imageUrl);

        if ($image !== null) {
            $data['logo'] = $image;
        }

        return $data;
    }
}
