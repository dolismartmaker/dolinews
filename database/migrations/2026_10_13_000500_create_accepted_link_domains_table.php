<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domains the super admin accepts as link targets on any sheet
     * (SPEC 8): a link there no longer counts in the domain alert. Kept
     * as normalized hosts, matched on themselves and their subdomains.
     */
    public function up(): void
    {
        Schema::create('accepted_link_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accepted_link_domains');
    }
};
