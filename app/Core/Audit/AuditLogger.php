<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Audit\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes transverse audit entries into core_audit_log.
 *
 * The current user is inferred from the authenticated context when
 * available; failures are logged and swallowed so auditing never breaks
 * the primary operation.
 */
class AuditLogger
{
    /**
     * Record an audited action.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        $user = Auth::user();
        $userId = $user instanceof User ? $user->getKey() : null;

        try {
            AuditEntry::query()->create([
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject !== null ? $subject->getKey() : null,
                'meta' => $meta,
            ]);
        } catch (Throwable $e) {
            Log::error('AuditLogger: failed to write audit entry', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
