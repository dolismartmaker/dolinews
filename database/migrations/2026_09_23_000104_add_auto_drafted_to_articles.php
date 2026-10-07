<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Machine-drafted announcements (SPEC 5.8).
     *
     * Unlike articles.auto_translated, which is operating data, this one
     * is a public mention: a reader is told when the text they are
     * reading was drafted from a changelog rather than written by the
     * editor announcing it. The review still read it and accepted it,
     * which is why the mention says drafted and not published.
     *
     * It also makes the corpus findable after the fact: a factual error
     * traced back to the writing endpoint is corrected on every article
     * that came out of it, which a flag nobody stored would not allow.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('auto_drafted')->default(false)->after('auto_translated');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('auto_drafted');
        });
    }
};
