<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Support;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Static callbacks named by config/admin.php, which cannot hold closures
 * (config:cache refuses them).
 *
 * The menu only hides: every screen checks its own access.
 */
class AdminCallbacks
{
    /**
     * Who is shown the server-side fix of a standing notice.
     */
    public static function isSuperAdmin(User $user): bool
    {
        return $user->is_super_admin && $user->active;
    }

    /**
     * Badge of the review queue: the articles awaiting a decision.
     */
    public static function pendingReviews(User $user): int
    {
        return Article::query()
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Badge of the reports screen (SPEC 9.9).
     */
    public static function openReports(User $user): int
    {
        return app(ReportService::class)->openCount();
    }

    /**
     * Impersonation trace of the package, written into core_audit_log next
     * to the other acts of the back-office rather than into a second table.
     *
     * The actor is named in the entry itself: AuditLogger takes user_id from
     * the session, which is already the target once the swap is done.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function traceImpersonation(Model $actor, string $description, array $properties): void
    {
        app(AuditLogger::class)->log('admin.'.$description, $actor, ['actor_id' => $actor->getKey()] + $properties);
    }
}
