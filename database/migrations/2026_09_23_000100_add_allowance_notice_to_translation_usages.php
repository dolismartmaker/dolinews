<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the editor was told its monthly translation allowance was spent
 * (SPEC 5.7).
 *
 * On the usage row rather than in the cache, and per period rather than
 * as a flag: the notice is due once a month at most, and a cache that
 * gets flushed - or a deployment that changes store - would mail the
 * same editor again the same afternoon. A column that lives beside the
 * counter it talks about cannot drift from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_usages', function (Blueprint $table): void {
            $table->timestamp('notified_at')->nullable()->after('characters');
        });
    }

    public function down(): void
    {
        Schema::table('translation_usages', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
