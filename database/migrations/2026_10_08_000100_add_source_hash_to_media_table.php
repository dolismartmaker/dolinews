<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sha256 of the bytes as received, before re-encoding (SPEC 4.4).
     *
     * media.hash is taken on the re-encoded file, which only the service
     * can produce: a client holding the original cannot compute it, so
     * it had no way to tell an image already on a sheet from a new one
     * without uploading it again. Null on the media deposited before.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->char('source_hash', 64)->nullable()->after('hash');
            $table->index(['editor_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['editor_id', 'source_hash']);
            $table->dropColumn('source_hash');
        });
    }
};
