<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The languages an editor wants its announcements translated into
     * (SPEC 5.7).
     *
     * Null means every language the service offers, which is the state
     * an editor starts in. Choosing is a legitimate editorial call - an
     * editor selling in two countries has no use for eight versions
     * nobody there reads - and it lowers what the monthly allowance is
     * spent on, since only the chosen languages are produced.
     *
     * Same convention as translation_mandates.locales, deliberately: two
     * lists of content locales, null meaning all of them, read the same
     * way in both places.
     */
    public function up(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->json('translation_locales')->nullable()->after('auto_translate');
        });
    }

    public function down(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn('translation_locales');
        });
    }
};
