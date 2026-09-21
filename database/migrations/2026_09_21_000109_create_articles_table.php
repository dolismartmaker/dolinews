<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->constrained('editors')->restrictOnDelete();
            // NullOnDelete on purpose: a deleted sheet must not erase the
            // dated history of the feed (SPEC D1), announcements live
            // without a project at all (SPEC 5.3).
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            // NullOnDelete on purpose: on account deletion the publication
            // data stays online in minimized form (SPEC 9.8).
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('focus', 20)->nullable();
            $table->string('title');
            $table->string('slug');
            $table->string('version', 32)->nullable();
            $table->string('summary', 500);
            $table->mediumText('body');
            $table->char('locale', 5);
            // Assigned at creation of EVERY article, even the first of its
            // group (SPEC 4.3): nulls would escape the (group, locale)
            // unique index and the original would need linking afterwards.
            $table->uuid('translation_group_id');
            $table->boolean('is_source')->default(true);
            $table->unsignedInteger('revision_number')->default(0);
            $table->unsignedInteger('source_revision_number')->nullable();
            $table->smallInteger('dolibarr_min')->nullable();
            $table->smallInteger('dolibarr_max')->nullable();
            $table->string('maturity', 20)->default('stable');
            $table->string('compat_status', 20)->default('declared');
            $table->string('status', 20)->default('draft');
            $table->string('publication_mode', 20)->nullable();
            $table->datetime('submitted_at')->nullable();
            // Review round (implementation of SPEC 5.1): every
            // resubmission moves it forward, accords only count within
            // the current round. Wall-clock comparison would be wrong at
            // second precision.
            $table->unsignedInteger('submission_seq')->default(1);
            $table->datetime('published_at')->nullable();
            $table->datetime('deleted_at')->nullable();
            $table->timestamps();

            // Indexes of SPEC 4.6: filtering is the core feature.
            $table->index(['status', 'published_at']);
            $table->index(['status', 'submitted_at']);
            $table->index(['dolibarr_min', 'dolibarr_max']);
            $table->index(['focus', 'published_at']);
            $table->index(['project_id', 'published_at']);
            $table->index(['editor_id', 'published_at']);
            $table->index(['author_user_id', 'status']);
            $table->index('published_at');
            // Slug uniqueness: covers project-bound articles. The slugs of
            // project-less announcements escape any index on a nullable
            // column: they are checked and suffixed application-side
            // (SPEC 4.3).
            $table->unique(['project_id', 'slug']);
            // One version per language per translation group (SPEC D14).
            $table->unique(['translation_group_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
