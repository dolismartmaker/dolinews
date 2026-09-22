<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table): void {
            $table->id();
            // Exactly one of the two is set: an article of the feed, or a
            // project sheet (SPEC 9.5 covers sheet impersonation). Cascade
            // and not nullOnDelete: a report whose target is gone has no
            // object left, and what stays opposable is moderation_log,
            // which keeps the act, its rule and its motive.
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('reason', 30);
            $table->text('body');
            // Mandatory: the team must be able to ask for the precision a
            // three-line report always lacks. Personal data, inventoried
            // as such (SPEC 9.8).
            $table->string('reporter_email');
            // Set when the reporter was logged in, so the team reads a
            // known account rather than an address alone.
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Interface locale of the reporter: the answer goes out in the
            // language the report came in, and the team knows before
            // opening the body that it is not in French.
            $table->char('locale', 5);
            $table->string('status', 20)->default('open');
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('handled_at')->nullable();
            // What the team decided and why, filled at handling time. A
            // report closed without a word is a report nobody can audit.
            $table->text('resolution')->nullable();
            $table->timestamps();

            // The queue is read by status, oldest first.
            $table->index(['status', 'created_at']);
            // Deduplication reads these two: a second report on a target
            // already flagged piles up without mailing the team again.
            $table->index(['article_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
