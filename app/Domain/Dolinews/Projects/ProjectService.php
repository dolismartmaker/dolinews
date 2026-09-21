<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectLink;
use App\Domain\Dolinews\Models\ProjectTranslation;
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
            'name', 'summary', 'description', 'license', 'status',
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
     * Add or update one translation of the sheet (SPEC D14).
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
                'name', 'summary', 'description',
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
