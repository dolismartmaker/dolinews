<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

/**
 * The fallback writer: states the facts, and nothing but the facts
 * (SPEC 5.8).
 *
 * Always available, so a deployment with no writing endpoint still
 * announces the security fix its readers deployed. A dry entry beats
 * silence on a release an integrator has to apply.
 *
 * It deliberately reproduces NOTHING of the release note: the version,
 * the date, the branch and the vulnerability identifiers are facts,
 * which no licence covers, whereas the note itself is somebody else's
 * text, published under the licence of the project it comes from and not
 * under the one this service publishes (SPEC D15). Whoever wants the
 * detail follows the link, which is what the link is for.
 */
class MechanicalReleaseWriter implements ReleaseWriter
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function write(DolibarrRelease $release, string $locale): ?WrittenRelease
    {
        $language = substr($locale, 0, 2);
        $version = $release->version();

        // Set on a copy and in its own statement: locale() doubles as a
        // static setter, so its return type says nothing useful.
        $day = $release->releasedAt->copy();
        $day->locale($language);
        $date = $day->isoFormat('LL');

        $summary = (string) trans(
            'La version :version du coeur Dolibarr est parue le :date.',
            ['version' => $version, 'date' => $date],
            $language,
        );

        if ($release->mentionsSecurity()) {
            $summary .= ' '.(string) trans(
                'Elle corrige des failles de sécurité.',
                [],
                $language,
            );
        }

        $lines = [
            $summary,
            '',
            '- '.(string) trans(
                'Branche :branch',
                ['branch' => $release->major.'.'.$release->minor],
                $language,
            ),
        ];

        $cves = $release->cveIdentifiers();

        if ($cves !== []) {
            $lines[] = '- '.(string) trans(
                'Identifiants de vulnérabilité cités : :list',
                ['list' => implode(', ', $cves)],
                $language,
            );
        }

        $lines[] = '';
        $lines[] = (string) trans(
            'Le détail des modifications est publié avec la version : :url',
            ['url' => $release->url],
            $language,
        );
        $lines[] = '';
        $lines[] = (string) trans(
            'Cette entrée signale la parution ; elle ne reprend pas le journal des modifications d\'origine.',
            [],
            $language,
        );

        return new WrittenRelease(
            'Dolibarr '.$version,
            $summary,
            implode("\n", $lines),
        );
    }
}
