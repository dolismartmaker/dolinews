<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly ceiling of the shared translation route for one editor
     * (SPEC 5.7), set by the super admin from the back-office. Null
     * means the deployment default, DOLINEWS_TRANSLATION_MONTHLY_CHARS.
     */
    public function up(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->unsignedInteger('translation_monthly_characters')->nullable()->after('translation_key_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn('translation_monthly_characters');
        });
    }
};
