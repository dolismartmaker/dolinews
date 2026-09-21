<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Observation data harvested from the reference git repositories
        // (SPEC 3.2/4.1): no link whatsoever with accounts. Only the
        // peppered hash of the commit address is stored, never the clear
        // address.
        Schema::create('known_committer_hashes', function (Blueprint $table): void {
            $table->id();
            $table->char('email_hash', 64);
            $table->string('source_repo', 255);
            $table->unsignedInteger('commit_count')->default(0);
            $table->datetime('first_seen_at')->nullable();
            $table->datetime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['email_hash', 'source_repo']);
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_committer_hashes');
    }
};
