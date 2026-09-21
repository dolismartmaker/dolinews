<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->char('locale', 5);
            $table->string('name');
            $table->string('summary', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_translations');
    }
};
