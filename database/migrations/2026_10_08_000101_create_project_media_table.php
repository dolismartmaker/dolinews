<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The screenshot gallery of a sheet (SPEC 4.2/4.4): an ordered list
     * of media with an optional caption.
     *
     * A table rather than a column of ids because each entry carries its
     * own caption and position. Both keys cascade: a gallery entry whose
     * sheet or file is gone has nothing left to show.
     */
    public function up(): void
    {
        Schema::create('project_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('caption', 255)->nullable();
            $table->nullableTimestamps();

            $table->unique(['project_id', 'media_id']);
            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_media');
    }
};
