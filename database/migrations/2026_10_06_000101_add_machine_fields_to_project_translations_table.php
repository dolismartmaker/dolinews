<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What tells a machine translation of a sheet from a human one, and
     * when it has gone stale (SPEC 5.7).
     *
     * `auto_translated` is the same operating flag announcements carry:
     * a regeneration only ever rewrites what the engine itself wrote,
     * never a translation somebody sat down and made.
     *
     * `source_fingerprint` does for a sheet what source_revision_number
     * does for an announcement, and it cannot be the same mechanism: a
     * sheet has no revision circuit and no revision number, it is edited
     * in place by its editor. The fingerprint of the source text it was
     * written against is therefore the only thing that can say, later,
     * that the sheet has moved on and the Spanish version now describes
     * something else.
     */
    public function up(): void
    {
        Schema::table('project_translations', function (Blueprint $table): void {
            $table->boolean('auto_translated')->default(false)->after('description');
            $table->char('source_fingerprint', 64)->nullable()->after('auto_translated');
        });
    }

    public function down(): void
    {
        Schema::table('project_translations', function (Blueprint $table): void {
            $table->dropColumn(['auto_translated', 'source_fingerprint']);
        });
    }
};
