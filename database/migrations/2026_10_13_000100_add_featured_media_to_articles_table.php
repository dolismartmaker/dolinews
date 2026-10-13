<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The image an author puts forward for the announcement: on the
        // card of the feed, in the sharing card, in the feeds. Part of the
        // article, so reviewed with it (SPEC 4.3).
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('featured_media_id')->nullable()->after('body')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('featured_media_id');
        });
    }
};
