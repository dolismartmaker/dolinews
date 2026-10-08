<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectLink;
use App\Domain\Dolinews\Models\ProjectMedia;
use App\Domain\Dolinews\Models\ProjectTranslation;
use App\Domain\Dolinews\Seo\PageLocale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Project sheets and their typed links (SPEC 4.2/8).
 *
 * The sheet carries NO Dolibarr compatibility (SPEC D1): anything dated
 * lives in the feed. The (type, external_id) unique constraint DETECTS
 * two accounts claiming the same Dolistore sheet; the resolution is
 * human (SPEC 9.5), surfaced as a claim for the moderation circuit.
 */
class ProjectService
{
    public function __construct(
        private readonly LinkPolicy $links,
    ) {}

    /**
     * Create a sheet for an editor.
     *
     * @param  array<string, mixed>  $payload  sheet fields
     */
    public function create(Editor $editor, array $payload): Project
    {
        $project = Project::query()->create([
            'editor_id' => $editor->getKey(),
            'slug' => $this->uniqueSlug((string) $payload['name']),
            'name' => (string) $payload['name'],
            // The language the sheet is written in (SPEC 4.2): stated
            // rather than guessed, because it decides both what the
            // reader is warned about and what an engine is told to
            // translate from.
            'locale' => (string) ($payload['locale'] ?? PageLocale::full((string) config('app.locale'))),
            'summary' => (string) $payload['summary'],
            'description' => $payload['description'] ?? null,
            'license' => $payload['license'] ?? null,
            'status' => 'active',
        ]);

        Log::info('ProjectService: sheet created', [
            'project_id' => $project->getKey(),
            'editor' => $editor->getKey(),
        ]);

        return $project;
    }

    /**
     * Update the reference version's fields. Translations are separate
     * rows (SPEC 4.2, D14).
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Project $project, array $payload): Project
    {
        $project->fill(array_intersect_key($payload, array_flip([
            'name', 'locale', 'summary', 'description', 'license', 'status',
        ])));
        $project->save();

        return $project;
    }

    /**
     * Add or replace a typed link. Refuses shorteners, validates the
     * scheme, extracts the Dolistore sheet id, and surfaces a claim
     * conflict when the (type, external_id) pair is already taken by
     * another sheet (SPEC 4.2/8/9.5).
     *
     * @throws ProjectException when the URL is refused or already
     *                          claimed by another sheet.
     */
    public function addLink(Project $project, LinkType $type, string $url, ?string $label = null): ProjectLink
    {
        $check = $this->links->validate($url);

        if (! $check['ok']) {
            throw new ProjectException($check['reason']);
        }

        $externalId = $this->links->extractExternalId($type, $url);

        if ($externalId !== null) {
            $taken = ProjectLink::query()
                ->where('type', $type->value)
                ->where('external_id', $externalId)
                ->where('project_id', '!=', $project->getKey())
                ->exists();

            if ($taken) {
                // Detection is automatic, resolution is human (SPEC 9.5):
                // surface the conflict to the moderation circuit.
                Log::warning('ProjectService: external id already claimed by another sheet', [
                    'project' => $project->getKey(),
                    'type' => $type->value,
                    'external_id' => $externalId,
                ]);

                throw new ProjectException(
                    'Cette fiche externe est déjà revendiquée par un autre projet : le conflit sera instruit par l\'équipe de modération.'
                );
            }
        }

        return ProjectLink::query()->create([
            'project_id' => $project->getKey(),
            'type' => $type,
            'url' => $url,
            'label' => $label,
            'position' => (int) ProjectLink::query()
                ->where('project_id', $project->getKey())
                ->max('position') + 1,
            'external_id' => $externalId,
        ]);
    }

    /**
     * Remove one link of the sheet.
     *
     * Deleted and not flagged: the link belongs to the editor's own sheet, it
     * carries no dated statement, and nothing in the moderation circuit refers
     * to it. Freeing its (type, external_id) pair is also what lets a sheet
     * hand a Dolistore id over after a transfer.
     */
    public function removeLink(Project $project, int $linkId): bool
    {
        /** @var ProjectLink|null $link */
        $link = $project->links()->whereKey($linkId)->first();

        if ($link === null) {
            Log::info('ProjectService: link already gone', [
                'project' => $project->getKey(),
                'link' => $linkId,
            ]);

            return false;
        }

        $link->delete();

        return true;
    }

    /**
     * Set or clear the logo of the sheet (SPEC 4.2).
     *
     * @throws ProjectException when the medium belongs to another editor.
     */
    public function setLogo(Project $project, ?Media $media): Project
    {
        if ($media !== null) {
            $this->assertOwnMedia($project, $media);
        }

        $project->logo_media_id = $media?->getKey();
        $project->save();

        Log::info('ProjectService: sheet logo set', [
            'project' => $project->getKey(),
            'media' => $media?->getKey(),
        ]);

        return $project;
    }

    /**
     * Add an image to the gallery of the sheet, or update the caption and
     * the place of one already in it (SPEC 4.2/4.4).
     *
     * Adding twice is not an error: a tool run again on the same sheet
     * finds its images in place and only rewrites what changed.
     *
     * @throws ProjectException when the medium belongs to another editor,
     *                          or when the gallery is full.
     */
    public function addToGallery(Project $project, Media $media, ?string $caption = null, ?int $position = null): ProjectMedia
    {
        $this->assertOwnMedia($project, $media);

        /** @var ProjectMedia|null $existing */
        $existing = $project->gallery()->where('media_id', $media->getKey())->first();

        if ($existing !== null) {
            $existing->caption = $caption;

            if ($position !== null) {
                $existing->position = $position;
            }

            $existing->save();

            return $existing;
        }

        $max = max(0, (int) config('dolinews.projects.gallery_max', 10));

        if ($project->gallery()->count() >= $max) {
            Log::info('ProjectService: gallery full', [
                'project' => $project->getKey(),
                'media' => $media->getKey(),
                'max' => $max,
            ]);

            throw new ProjectException(
                'La galerie de cette fiche porte déjà '.$max.' images, le maximum de ce service. '
                .'Retirez-en une avant d\'en ajouter une autre.',
                ProjectException::GALLERY_FULL,
            );
        }

        return ProjectMedia::query()->create([
            'project_id' => $project->getKey(),
            'media_id' => $media->getKey(),
            'position' => $position ?? (int) $project->gallery()->max('position') + 1,
            'caption' => $caption,
        ]);
    }

    /**
     * Take an image out of the gallery. The file itself stays: the purge
     * removes it later if nothing else holds it.
     */
    public function removeFromGallery(Project $project, int $mediaId): bool
    {
        $removed = $project->gallery()->where('media_id', $mediaId)->delete();

        if ($removed === 0) {
            Log::info('ProjectService: gallery image already gone', [
                'project' => $project->getKey(),
                'media' => $mediaId,
            ]);

            return false;
        }

        return true;
    }

    /**
     * A sheet shows the files of its own editor only: a medium is
     * deposited under an editor, and borrowing another one's would put
     * their screenshot under someone else's name.
     *
     * @throws ProjectException
     */
    private function assertOwnMedia(Project $project, Media $media): void
    {
        if ($media->editor_id === $project->editor_id) {
            return;
        }

        Log::warning('ProjectService: medium of another editor refused on a sheet', [
            'project' => $project->getKey(),
            'media' => $media->getKey(),
            'media_editor' => $media->editor_id,
        ]);

        throw new ProjectException(
            'Ce média a été déposé pour un autre éditeur que celui de la fiche.',
            ProjectException::FOREIGN_MEDIA,
        );
    }

    /**
     * Add or update one translation of the sheet (SPEC D14).
     *
     * `auto_translated` and `source_fingerprint` say who wrote this
     * version and against which state of the sheet (SPEC 5.7): a
     * regeneration only ever rewrites what the engine itself produced,
     * and only when the sheet has moved on since.
     *
     * @param  array<string, mixed>  $payload
     */
    public function translate(Project $project, string $locale, array $payload): ProjectTranslation
    {
        /** @var ProjectTranslation|null $existing */
        $existing = $project->translations()
            ->where('locale', $locale)
            ->first();

        if ($existing !== null) {
            $existing->fill(array_intersect_key($payload, array_flip([
                'name', 'summary', 'description', 'auto_translated', 'source_fingerprint',
            ])));
            $existing->save();

            return $existing;
        }

        return ProjectTranslation::query()->create([
            'project_id' => $project->getKey(),
            'locale' => $locale,
            'name' => (string) $payload['name'],
            'summary' => (string) $payload['summary'],
            'description' => $payload['description'] ?? null,
            'auto_translated' => (bool) ($payload['auto_translated'] ?? false),
            'source_fingerprint' => $payload['source_fingerprint'] ?? null,
        ]);
    }

    /**
     * Slug unique across sheets: two editors may share a project name,
     * the public identifier cannot.
     */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 80));

        if ($base === '') {
            $base = 'projet';
        }

        $slug = $base;
        $suffix = 1;

        while (Project::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
