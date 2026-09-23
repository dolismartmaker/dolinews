<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_links', function (Blueprint $table): void {
            $table->id();

            // The address the link was mailed to. No account exists yet
            // for a pending confirmation, and none must: the form takes
            // an address typed by a stranger, so creating the account
            // before the click would let anyone fill the table with
            // other people's addresses.
            $table->string('email');

            $table->char('token', 32)->unique();

            // confirm: turns the pending watch into a real subscription.
            // manage: opens the preferences page of an existing account.
            $table->string('purpose', 10);

            // What the confirmation will apply: the watched project or
            // editor, and its filter. Null for a manage link.
            $table->json('payload')->nullable();

            $table->timestamp('expires_at');

            // Kept rather than deleted on use, so that a second click on
            // the same link says "already done" instead of 404, which
            // reads as a broken service.
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index(['email', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_links');
    }
};
