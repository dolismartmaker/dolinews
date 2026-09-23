<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // A reader who subscribed from a project sheet has no
            // password and never had one: the address is the whole
            // subscription (SPEC 6.4). A null password authenticates
            // nobody - Hash::check against null never passes - so the
            // account is reachable by its tokens only, and the classic
            // login stays the door of whoever writes.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
