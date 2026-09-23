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
            // Security announcements whatever the project (SPEC 6.4), a
            // watch of its own and not a focus filter on watches_all:
            // expressed as a filter, it read as "the whole feed, narrowed
            // to security", so asking for security alone set a filter on
            // a watch that was off and sent nothing at all.
            $table->boolean('watches_all_security')->default(false)->after('watches_all');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('watches_all_security');
        });
    }
};
