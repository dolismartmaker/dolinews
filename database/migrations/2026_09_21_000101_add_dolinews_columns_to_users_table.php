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
            // Public profile columns and the two spec flags (SPEC 4.1).
            $table->string('display_name', 100)->nullable()->after('name');
            $table->text('bio')->nullable()->after('display_name');
            $table->string('website')->nullable()->after('bio');
            $table->boolean('is_moderator')->default(false)->after('website');
            $table->boolean('is_super_admin')->default(false)->after('is_moderator');
            $table->boolean('active')->default(true)->after('is_super_admin');
            // Personal RSS feed token (SPEC 6.4): revocable by regeneration,
            // null until the reader asks for a personal feed.
            $table->char('feed_token', 32)->nullable()->unique()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'display_name',
                'bio',
                'website',
                'is_moderator',
                'is_super_admin',
                'active',
                'feed_token',
            ]);
        });
    }
};
