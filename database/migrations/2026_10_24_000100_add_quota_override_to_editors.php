<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publication quota raised for one editor until a date (SPEC 5.3),
     * set by the super admin from the back-office. The values apply up to
     * quota_override_until and never after: the deployment defaults come
     * back by themselves. Null values mean the deployment defaults.
     */
    public function up(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quota_bucket_capacity')->nullable()->after('translation_monthly_characters');
            $table->unsignedSmallInteger('quota_queue_ceiling')->nullable()->after('quota_bucket_capacity');
            $table->dateTime('quota_override_until')->nullable()->after('quota_queue_ceiling');
        });
    }

    public function down(): void
    {
        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn(['quota_bucket_capacity', 'quota_queue_ceiling', 'quota_override_until']);
        });
    }
};
