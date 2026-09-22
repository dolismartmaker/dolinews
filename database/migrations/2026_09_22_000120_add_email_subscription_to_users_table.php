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
            // Email subscription (SPEC 6.4). The tokenized feed reaches
            // whoever runs a feed reader; a Dolibarr user does not, and
            // an announcement nobody is told about serves nobody.
            $table->string('email_digest', 10)->default('none')->after('feed_token');

            // Following the WHOLE feed has no target row to carry it,
            // unlike project_watches and editor_watches: it lives on the
            // account, with the same optional filters.
            $table->boolean('watches_all')->default(false)->after('email_digest');
            $table->json('watch_all_focus_filter')->nullable()->after('watches_all');
            $table->json('watch_all_maturity_filter')->nullable()->after('watch_all_focus_filter');

            // Low bound of the next mail: nothing published before it is
            // ever sent. Set when the subscription is turned on, so that
            // subscribing never mails the archive, and advanced on every
            // send. A back-dated publication (SPEC 5.1) falls below it by
            // construction and mails nobody, which is what saves a
            // catalogue of a hundred back-dated entries from becoming a
            // hundred mails.
            $table->timestamp('digest_cursor_at')->nullable()->after('watch_all_maturity_filter');
            $table->timestamp('digest_sent_at')->nullable()->after('digest_cursor_at');

            // Unsubscribe link of the footer: the reader must be able to
            // leave from the mail itself, without a session and without
            // remembering a password.
            $table->char('unsubscribe_token', 32)->nullable()->unique()->after('digest_sent_at');

            // Language of the mails. The interface locale lives in the
            // session, which no scheduled command can read.
            $table->string('locale', 5)->nullable()->after('unsubscribe_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'email_digest',
                'watches_all',
                'watch_all_focus_filter',
                'watch_all_maturity_filter',
                'digest_cursor_at',
                'digest_sent_at',
                'unsubscribe_token',
                'locale',
            ]);
        });
    }
};
