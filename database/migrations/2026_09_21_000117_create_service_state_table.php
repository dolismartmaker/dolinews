<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Technical key/value store, an implementation detail beyond the
        // spec's frozen data model: it persists one-way service-state
        // facts that must survive their triggering condition, e.g. the
        // bootstrap phase closure (SPEC 5.1) which never reopens even if
        // the team later drops below the floor of six.
        Schema::create('service_state', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_state');
    }
};
