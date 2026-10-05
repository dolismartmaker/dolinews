<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The content languages a moderator declares reading (SPEC 5.1).
     *
     * Null means every language, which is the state an account starts in
     * and the only one that keeps an existing deployment unchanged. Same
     * convention as editors.translation_locales and
     * translation_mandates.locales: a list of content locales, null
     * meaning all of them, an emptied selection reading as the default
     * rather than as "no language at all".
     *
     * It is NOT derived from users.locale, which says what interface the
     * account reads, not what it can review: a moderator browsing in
     * French may well read Spanish and Italian, and one browsing in
     * Polish may read nothing else.
     *
     * What it drives is who gets the circuit mail, never who may review:
     * the queue stays open to the whole team and the quorum does not
     * move (SPEC 5.1/9.1).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('review_locales')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('review_locales');
        });
    }
};
