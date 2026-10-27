<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ingestion API accepts a report address of 2048 characters, the
     * limit of a sheet link (SPEC 8), but the column was created at the
     * default 255: on MySQL a longer CI run address failed at insertion
     * after passing validation. SQLite ignores the length, which is why
     * the suite never saw it.
     */
    public function up(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->string('source_url', 2048)->change();
        });
    }

    public function down(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->string('source_url')->change();
        });
    }
};
