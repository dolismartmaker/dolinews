<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\FindProjects;
use App\Mcp\Tools\GetAnnouncement;
use App\Mcp\Tools\GetEditor;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\SearchAnnouncements;
use Laravel\Mcp\Server;

/**
 * The public MCP read server (SPEC 6.7): anonymous, read-only, nothing
 * more than what a visitor of the site reads.
 *
 * No tool writes. A token would submit here as through the API, and D6
 * would hold, but the resource the quotas protect is the review's time
 * (SPEC 5.3), and the declaration a submission makes (D15) has to stay
 * an act of its author. The editor's publishing tools remain the way to
 * write (SPEC 5.2).
 */
class DolinewsServer extends Server
{
    protected string $version = '1.0.0';

    /**
     * Said once at the handshake, and repeated in short in every result
     * (ReadingPayload::NOTICE). The service does not control the sentence
     * a client draws from its results; these are the rules that keep it
     * from saying what the site never says (D1, SPEC 10, SPEC 6.1).
     */
    protected string $instructions = <<<'MARKDOWN'
        DoliNews is the announcement feed of the Dolibarr ecosystem. Editors of Dolibarr modules and services announce new versions, security fixes, compatibility work and end of life there. Every announcement is reviewed by a moderation team before it is published.

        When you relay what these tools return:
        - DoliNews records what was announced, and when. It never states the current state of a module. Always give the announcement date and say "announced": "version 2.3, announced on 2026-03-02 for Dolibarr 18 to 24". Never turn an announced range into "is compatible with" or "works with".
        - A module absent from DoliNews may still exist: its editor may simply not publish here. Say so rather than concluding it does not exist.
        - Test results are published by the editor and are declarative. Never present them as a certification or a quality rating.
        - Only stable releases are returned unless a maturity (alpha, beta, rc, deprecated) is asked for explicitly.
        - Contents are published under CC BY-SA 4.0. When quoting an announcement, name its editor and give its link.
        - Pass the reader's language as "locale": each announcement is then returned once, in that language when a version exists, in its original language otherwise.

        Typical path: find_projects or search_announcements, then get_project, get_announcement or get_editor for the details.
        MARKDOWN;

    /**
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        SearchAnnouncements::class,
        GetAnnouncement::class,
        FindProjects::class,
        GetProject::class,
        GetEditor::class,
    ];

    /**
     * The name is the deployment's: the repository is public, and a
     * third-party instance answers under its own name (SPEC 9.7).
     */
    protected function boot(): void
    {
        $this->name = (string) config('app.name', 'DoliNews');
    }
}
