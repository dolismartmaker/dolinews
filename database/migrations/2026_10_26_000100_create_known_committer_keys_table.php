<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fingerprints of the OpenPGP keys that signed commits of a
        // commit address in a reference repository (SPEC 3.2, strong
        // level). Observation data like known_committer_hashes: keyed by
        // the peppered address hash, no link with accounts.
        Schema::create('known_committer_keys', function (Blueprint $table): void {
            $table->id();
            $table->char('email_hash', 64);
            $table->string('source_repo', 255);
            // 40 hex digits for a v4 key, 64 for v5/v6.
            $table->string('key_fingerprint', 64);
            $table->timestamps();

            // Named by hand: the generated name runs to 66 characters,
            // past the 64 MySQL allows for an identifier.
            $table->unique(['email_hash', 'source_repo', 'key_fingerprint'], 'known_committer_keys_identity_unique');
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_committer_keys');
    }
};
