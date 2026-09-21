<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_watches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('editor_id')->constrained('editors')->cascadeOnDelete();
            $table->json('focus_filter')->nullable();
            $table->json('maturity_filter')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'editor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_watches');
    }
};
