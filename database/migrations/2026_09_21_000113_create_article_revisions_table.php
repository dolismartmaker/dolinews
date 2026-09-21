<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            // payload: the changed fields only. snapshot: the COMPLETE
            // article state before application (SPEC 5.4), because
            // replaying three diffs backwards fails at the first mistake.
            $table->json('payload');
            $table->json('snapshot');
            $table->string('motive', 255);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->datetime('decided_at')->nullable();

            $table->index(['article_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_revisions');
    }
};
