<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized hosts of the sheet links and of the editors' websites
     * (SPEC 8), so the back-office counts the links off the editor's
     * domain in SQL and sorts on that count. Both are derived columns,
     * written by the models on save; this migration fills the existing
     * rows with the same normalization (lowercase, no leading "www.").
     */
    public function up(): void
    {
        Schema::table('project_links', function (Blueprint $table): void {
            $table->string('host', 255)->nullable()->after('url');
        });

        Schema::table('editors', function (Blueprint $table): void {
            $table->string('website_host', 255)->nullable()->after('website');
        });

        $hostOf = static function (?string $url): ?string {
            $host = $url === null ? null : parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                return null;
            }

            $host = mb_strtolower($host);

            return preg_replace('/^www\./', '', $host) ?? $host;
        };

        foreach (DB::table('project_links')->select(['id', 'url'])->get() as $link) {
            DB::table('project_links')->where('id', $link->id)->update(['host' => $hostOf($link->url)]);
        }

        foreach (DB::table('editors')->select(['id', 'website'])->get() as $editor) {
            DB::table('editors')->where('id', $editor->id)->update(['website_host' => $hostOf($editor->website)]);
        }
    }

    public function down(): void
    {
        Schema::table('project_links', function (Blueprint $table): void {
            $table->dropColumn('host');
        });

        Schema::table('editors', function (Blueprint $table): void {
            $table->dropColumn('website_host');
        });
    }
};
