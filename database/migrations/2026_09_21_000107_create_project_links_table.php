<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('url', 2048);
            $table->string('label')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Dolistore sheet id extracted from the URL when possible; the
            // (type, external_id) unique constraint DETECTS two accounts
            // claiming the same sheet (SPEC 4.2/9.5), humans resolve it.
            $table->string('external_id', 64)->nullable();
            $table->datetime('checked_at')->nullable();
            $table->boolean('is_broken')->default(false);
            $table->timestamps();

            // Composite unique with a nullable column: rows without an
            // external_id escape it on MySQL and SQLite alike (NULLs are
            // distinct), which is exactly the spec's intent.
            $table->unique(['type', 'external_id']);
            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
    }
};
