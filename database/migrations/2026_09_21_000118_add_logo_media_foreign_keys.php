<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Logo foreign keys land after the media table exists: editors and
        // projects declared logo_media_id earlier without the constraint
        // (media references editors back, the cycle had to be broken).
        Schema::table('editors', function (Blueprint $table): void {
            $table->foreign('logo_media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign('logo_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->dropForeign(['logo_media_id']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['logo_media_id']);
        });
    }
};
