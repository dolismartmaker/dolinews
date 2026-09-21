<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The result of a successful verification, attached to an account
        // and revocable (SPEC 3.2/4.1). Distinct table from the user: one
        // person may accumulate several proofs (several commit addresses).
        Schema::create('contributor_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 20);
            $table->char('email_hash', 64);
            $table->string('source_repo', 255);
            $table->unsignedInteger('commit_count')->default(0);
            $table->datetime('verified_at');
            $table->datetime('revoked_at')->nullable();
            $table->timestamps();

            // One hash can be bound to a single account (SPEC 3.4): this
            // blocks multiple accounts on one git identity even after a
            // first account is deleted, since the row survives with
            // revoked_at set (SPEC 9.8 keeps proofs after deletion).
            $table->unique('email_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributor_proofs');
    }
};
