<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periodic check of the report an attestation points to (SPEC 8, 10).
     *
     * The value of an indicator is the report behind it. A CI run expires
     * with its artifacts, so the link is checked like a sheet link and the
     * indicator says when its report can no longer be read.
     */
    public function up(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->dateTime('checked_at')->nullable()->after('signature');
            $table->boolean('is_broken')->default(false)->after('checked_at');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->dropIndex(['checked_at']);
            $table->dropColumn(['checked_at', 'is_broken']);
        });
    }
};
