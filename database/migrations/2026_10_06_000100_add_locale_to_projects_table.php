<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The language the reference sheet is written in (SPEC 4.2).
     *
     * An announcement has always carried its locale; a sheet never did,
     * and that silence has two costs. The reader opening a sheet in a
     * language it does not exist in is told nothing, where the feed
     * names the language of an announcement nobody translated
     * (SPEC 6.1). And an engine asked to translate the sheet has no
     * source language to declare, which is exactly the parameter that
     * keeps a translator from guessing wrong on a short text.
     *
     * The existing sheets are French: they were deposited by the
     * catalogue script, which reads the descriptor of modules written
     * here. A deployment that is not ours gets the locale of its
     * application instead, and every sheet created from now on carries
     * the language of the account that writes it.
     */
    public function up(): void
    {
        $default = (string) config('app.locale', 'fr');
        $content = (array) config('dolinews.content_locales', ['fr_FR']);
        $fallback = 'fr_FR';

        foreach ($content as $locale) {
            if (str_starts_with((string) $locale, $default.'_')) {
                $fallback = (string) $locale;
                break;
            }
        }

        Schema::table('projects', function (Blueprint $table) use ($fallback): void {
            $table->char('locale', 5)->default($fallback)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
