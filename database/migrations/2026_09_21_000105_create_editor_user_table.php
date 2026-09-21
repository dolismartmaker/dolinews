<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->constrained('editors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10);
            $table->timestamps();

            $table->unique(['editor_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_user');
    }
};
