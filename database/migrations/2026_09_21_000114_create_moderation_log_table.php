<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_log', function (Blueprint $table): void {
            $table->id();
            // Nullable: the automatic cancellation of an unconfirmed act
            // is written by the service itself and must be told apart
            // from a cancellation decided by a human (SPEC 4.5).
            $table->foreignId('moderator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('editors')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rule_ref', 20)->nullable();
            $table->text('motive');
            // Conflict-of-interest withdrawals need a second moderator's
            // confirmation within seven days (SPEC 9.6).
            $table->boolean('requires_confirmation')->default(false);
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('confirmed_at')->nullable();
            // Implementation column beyond the spec's frozen set: marks a
            // withdrawal answering a legal obligation, which stays in
            // force without confirmation (SPEC 9.6 exception). The
            // confirmation then only documents it.
            $table->boolean('is_legal')->default(false);
            $table->timestamps();

            $table->index('created_at');
            $table->index(['requires_confirmation', 'confirmed_at']);
            $table->index('article_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_log');
    }
};
