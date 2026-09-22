<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Translation mandates (SPEC 5.6): an editor delegates the right to
     * submit language versions of its announcements to a contributor
     * account that belongs to no editor of its own.
     *
     * The grant and the revocation live in the row itself rather than in
     * moderation_log: a mandate is an act of an editor, not an act of
     * moderation, and SPEC 9.4 keeps that journal for the latter. The
     * row is never deleted, so a revoked mandate stays readable.
     */
    public function up(): void
    {
        Schema::create('translation_mandates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->constrained('editors')->cascadeOnDelete();
            $table->foreignId('translator_user_id')->constrained('users')->cascadeOnDelete();
            // Null scopes the mandate to the editor's whole catalogue.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            // Null means every content locale of the service: an editor
            // handing its catalogue to a translation desk should not have
            // to re-grant on the day a tenth language opens.
            $table->json('locales')->nullable();
            $table->foreignId('granted_by_user_id')->constrained('users');
            $table->timestamp('granted_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // One live mandate per translator and scope; the uniqueness
            // covers revoked rows too, which re-granting reopens rather
            // than duplicates.
            $table->unique(['editor_id', 'translator_user_id', 'project_id']);
            $table->index(['translator_user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_mandates');
    }
};
