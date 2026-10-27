<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Attestations;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Test attestations (SPEC 10): indicators pushed by the editors for
 * their own sheets.
 *
 * These indicators are NOT a certification. While the capTests
 * instances are hosted by the editors themselves, the ingestion API
 * trusts its caller and the indicator stays declarative: the interface
 * vocabulary ("tests publiés par l'éditeur", never "certifié" nor
 * "qualité") is part of the contract.
 */
class AttestationService
{
    /**
     * Record an attestation pushed through the ingestion API.
     *
     * @param  array<string, mixed>  $payload  validated attestation fields
     */
    public function record(Project $project, array $payload, ?Article $article = null): Attestation
    {
        $attestation = Attestation::query()->create([
            'project_id' => $project->getKey(),
            'article_id' => $article?->getKey(),
            'source_type' => $payload['source_type'],
            'source_url' => (string) $payload['source_url'],
            'confidence' => $payload['confidence'] ?? 'self_declared',
            'metric' => (string) $payload['metric'],
            'value' => (string) $payload['value'],
            'unit' => $payload['unit'] ?? null,
            'measured_at' => $payload['measured_at'] ?? now(),
            'received_at' => now(),
            'signature' => $payload['signature'] ?? null,
        ]);

        Log::info('AttestationService: attestation recorded', [
            'attestation_id' => $attestation->getKey(),
            'project_id' => $project->getKey(),
        ]);

        return $attestation;
    }

    /**
     * The attestations attached to an announcement, whichever of its
     * language versions they were pushed against: the tests ran on the
     * version, not on one translation of its text.
     *
     * @return Collection<int, Attestation>
     */
    public function forArticle(Article $article): Collection
    {
        if ($article->project_id === null) {
            return new Collection;
        }

        return Attestation::query()
            ->where('project_id', $article->project_id)
            ->whereIn('article_id', Article::query()
                ->select('id')
                ->where('translation_group_id', $article->translation_group_id))
            ->orderByDesc('measured_at')
            ->orderByDesc('received_at')
            ->limit(10)
            ->get();
    }

    /**
     * The freshness wording of an attestation (SPEC 10): the date of the
     * last execution says more than any percentage.
     */
    public function freshness(Attestation $attestation): string
    {
        $measured = $attestation->measured_at;

        if ($measured === null) {
            return __('date d\'exécution inconnue');
        }

        $days = (int) round($measured->diffInDays(now()));

        return match (true) {
            $days === 0 => __('dernière exécution aujourd\'hui'),
            $days === 1 => __('dernière exécution hier'),
            $days < 30 => trans_choice('dernière exécution il y a :count jour|dernière exécution il y a :count jours', $days),
            $days < 365 => trans_choice('dernière exécution il y a :count mois|dernière exécution il y a :count mois', (int) round($days / 30)),
            default => __('dernière exécution il y a plus d\'un an'),
        };
    }
}
