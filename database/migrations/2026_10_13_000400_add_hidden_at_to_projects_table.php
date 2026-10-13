<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Masking of a sheet by the moderation team (SPEC 4.2, 9.3).
     *
     * A column of its own and not a fourth value of `status`: the status
     * is the editor's statement about its project (active, unmaintained,
     * archived), the masking is a moderation act on top of it. Lifting
     * the act gives the editor's status back untouched.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dateTime('hidden_at')->nullable()->after('status');
            $table->index('hidden_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['hidden_at']);
            $table->dropColumn('hidden_at');
        });
    }
};
