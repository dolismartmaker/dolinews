<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editors', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_email');
            // Filled by the media migration binding; unsignedBigInteger now,
            // foreign key later, so editors and media can reference each
            // other (logo_media_id points at media, media.editor_id at
            // editors).
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->datetime('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editors');
    }
};
