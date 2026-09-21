<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('visibility', 20);
            $table->string('decision', 30)->nullable();
            $table->string('rule_ref', 20)->nullable();
            $table->text('body');
            // The review round this message belongs to: an accord only
            // counts within the article's current round (SPEC 5.1).
            $table->unsignedInteger('submission_seq')->default(1);
            $table->timestamps();

            $table->index(['article_id', 'visibility', 'created_at']);
            $table->index(['article_id', 'decision', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_messages');
    }
};
