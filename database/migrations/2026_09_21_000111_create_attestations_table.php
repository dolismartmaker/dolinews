<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('source_type', 20);
            $table->string('source_url');
            $table->string('confidence', 30)->default('self_declared');
            $table->string('metric', 50);
            $table->string('value');
            $table->string('unit', 20)->nullable();
            $table->datetime('measured_at')->nullable();
            $table->datetime('received_at');
            $table->string('signature')->nullable();

            $table->index(['project_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attestations');
    }
};
