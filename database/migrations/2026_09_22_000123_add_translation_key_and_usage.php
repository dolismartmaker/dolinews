<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two ways an editor's announcements get translated (SPEC 5.7).
     *
     * translation_api_key holds the editor's own key, encrypted at rest
     * and never shown again. An editor that sets one leaves the shared
     * route entirely: it pays its own supplier and owes the service
     * nothing, which is what keeps translation free of charge here
     * (SPEC 12).
     *
     * translation_usages counts what the SHARED route spent, per editor
     * and per month. An editor on its own key is counted nowhere, having
     * nothing to draw on. The counter exists to share a common resource,
     * not to bill anything.
     */
    public function up(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->text('translation_api_key')->nullable()->after('auto_translate');
            $table->timestamp('translation_key_set_at')->nullable()->after('translation_api_key');
        });

        Schema::create('translation_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('editor_id')->constrained('editors')->cascadeOnDelete();
            // YYYY-MM: a month is the period every ceiling is expressed
            // in, and a row per month keeps the history readable.
            $table->char('period', 7);
            $table->unsignedBigInteger('characters')->default(0);
            $table->timestamps();

            $table->unique(['editor_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_usages');

        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn(['translation_api_key', 'translation_key_set_at']);
        });
    }
};
