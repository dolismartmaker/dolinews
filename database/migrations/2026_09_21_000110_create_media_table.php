<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->nullable()->constrained('editors')->nullOnDelete();
            // Nullable on purpose: the two-step illustrated publication
            // (SPEC 5.2) uploads the media first, the article references
            // them later. article_id is bound when the article is created.
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            // sha256 of the re-encoded file: deduplication key (SPEC 7).
            $table->char('hash', 64);
            $table->string('alt', 255)->nullable();
            $table->nullableTimestamps();

            $table->index('hash');
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
