<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic translation (SPEC 5.7).
     *
     * editors.auto_translate is the editor's opt-in, never on by
     * default: the service would otherwise make an editor say, in
     * languages it does not read, things it never wrote, under its own
     * name and under the operator's editorial responsibility (SPEC 9.7).
     *
     * articles.auto_translated marks what the engine produced. It is
     * operating data, not a public mention: what it protects is a human
     * translator's work, which a regeneration must never overwrite.
     */
    public function up(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->boolean('auto_translate')->default(false)->after('verified_at');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('auto_translated')->default(false)->after('is_source');
        });
    }

    public function down(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn('auto_translate');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('auto_translated');
        });
    }
};
