<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->constrained('editors')->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('summary', 255);
            $table->text('description')->nullable();
            $table->string('license', 50)->nullable();
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['editor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
