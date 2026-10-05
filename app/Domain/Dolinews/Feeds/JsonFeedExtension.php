<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Models\Article;

/**
 * What a third-party client needs to render an announcement itself,
 * carried in the JSON feed as a `_dolinews` member of each item
 * (SPEC 6.4).
 *
 * JSON Feed reserves underscore-prefixed members for extensions, so a
 * reader that ignores this one keeps working. The surface it serves is
 * a section of someone else's site - a WordPress block, a dashboard -
 * and the reason it exists is that the plain item cannot hold the
 * rules the spec puts on a card: the Dolibarr range written short
 * (SPEC 6.1), a maturity badge never shown without its age
 * (SPEC 6.3), and the language of an announcement nobody translated
 * into the reader's (SPEC 6.1).
 *
 * Both halves travel: the raw values, for a client that lays out its
 * own card, and the wording, so that nobody has to re-translate into
 * ten languages what the service already says - and so that no
 * integration ends up writing "modules compatible v22", which D1
 * forbids and which the raw bounds alone invite.
 *
 * The API is NOT extended the same way: its contract is frozen
 * (SPEC D12), and the strict locale filter it keeps makes it the wrong
 * surface for a reading widget anyway.
 */
final class JsonFeedExtension
{
    /**
     * @param  string  $readerLocale  the locale the feed was asked in
     * @return array<string, mixed>
     */
    public static function for(Article $article, string $readerLocale): array
    {
        $editor = $article->editor;
        $project = $article->project;

        return [
            'id' => $article->getKey(),
            'locale' => $article->locale,
            'type' => $article->type->value,
            'focus' => $article->focus?->value,
            'maturity' => $article->maturity->value,
            'compat_status' => $article->compat_status->value,
            'version' => $article->version,
            // The announced floor, which drops the module builder's
            // default: a bound nobody chose was never announced
            // (SPEC 6.1). The ceiling is always typed by hand.
            'dolibarr_min' => $article->announcedDolibarrMin(),
            'dolibarr_max' => $article->dolibarr_max,
            'editor' => [
                'slug' => $editor->slug,
                'name' => $editor->name,
            ],
            'project' => $project === null ? null : [
                'slug' => $project->slug,
                'name' => $project->name,
            ],
            'labels' => [
                'focus' => $article->focus?->label(),
                'maturity' => $article->maturity->label(),
                'announced_age' => $article->announcedAge(),
                'dolibarr' => $article->announcedDolibarrLabel(),
                'language' => $article->foreignLanguageLabel($readerLocale),
            ],
        ];
    }
}
