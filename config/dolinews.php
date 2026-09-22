<?php

declare(strict_types=1);

// DoliNews service configuration (SPEC.md). Values left open by the spec
// (section 15) are environment-tunable and ship with documented defaults.

return [

    // Contribution verification (SPEC 3).
    'verification' => [
        // Application pepper for sha256(pepper || normalised address).
        // MUST be set in .env and NEVER changed or regenerated: changing
        // it invalidates every hash ever computed (SPEC 3.2).
        'pepper' => env('DOLINEWS_COMMITTER_PEPPER', ''),
        // Reference repositories harvested into known_committer_hashes.
        // Paths point at local clones; a git repository is distributable,
        // the verification must survive the forge disappearing (SPEC D4).
        'repositories' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DOLINEWS_REFERENCE_REPOS', ''))
        ))),
        // Minutes a possession code stays valid.
        'email_code_ttl' => (int) env('DOLINEWS_VERIFY_CODE_TTL', 60),
        // Qualification attempts per hour. Starting the flow mails a
        // code to a THIRD PARTY address read from the public committer
        // index, so an unbounded endpoint is a mail bomber aimed at
        // someone who never asked for anything.
        'attempts_per_account' => (int) env('DOLINEWS_VERIFY_ATTEMPTS_ACCOUNT', 5),
        'attempts_per_ip' => (int) env('DOLINEWS_VERIFY_ATTEMPTS_IP', 15),
    ],

    // Publication review (SPEC 5.1).
    'review' => [
        // Moderators whose acceptance publishes an article. The author of
        // an article never counts in it (SPEC 5.1).
        'quorum' => (int) env('DOLINEWS_REVIEW_QUORUM', 3),
        // Active moderators floor: reaching it closes the bootstrap phase
        // (SPEC 5.1/9.1).
        'moderator_floor' => (int) env('DOLINEWS_MODERATOR_FLOOR', 6),
        // Bootstrap phase: ceiling of articles published without quorum,
        // the phase never reopens once closed (SPEC 5.1). A last-resort
        // bound only: what actually closes the phase is a third-party
        // submission or the moderator floor, neither of which is tunable.
        'bootstrap_ceiling' => (int) env('DOLINEWS_BOOTSTRAP_CEILING', 50),
        // Days without activity before the automatic reminder fires, both
        // ways: team for a pending article, author for requested changes
        // (SPEC 5.1). Internal mechanism, never a public commitment.
        'reminder_days' => (int) env('DOLINEWS_REVIEW_REMINDER_DAYS', 3),
        // Decisions the public observed-delay median is computed on.
        'median_window' => (int) env('DOLINEWS_REVIEW_MEDIAN_WINDOW', 20),
    ],

    // Publication quota (SPEC 5.3). Values are defaults: the spec leaves
    // them to observation of the real throughput (SPEC 15).
    'quota' => [
        // Days to accrue one publication token.
        'token_days' => (int) env('DOLINEWS_QUOTA_TOKEN_DAYS', 7),
        // Token bucket ceiling: the most a project can bank.
        'bucket_capacity' => (int) env('DOLINEWS_QUOTA_BUCKET_CAPACITY', 3),
        // Simultaneous pending articles per editor, every language and
        // project combined, translations included (SPEC 5.3).
        'queue_ceiling' => (int) env('DOLINEWS_QUEUE_CEILING', 5),
    ],

    // Media intake (SPEC 7, D7).
    'media' => [
        // Hard upload ceiling in bytes, before re-encoding.
        'max_bytes' => (int) env('DOLINEWS_MEDIA_MAX_BYTES', 8 * 1024 * 1024),
        // Longest image side after re-encoding.
        'max_dimension' => (int) env('DOLINEWS_MEDIA_MAX_DIMENSION', 1600),
        // Orphan purge grace in hours (SPEC 5.2).
        'orphan_hours' => (int) env('DOLINEWS_MEDIA_ORPHAN_HOURS', 24),
    ],

    // Outgoing links (SPEC 8).
    'links' => [
        // URL shorteners refused outright: they mask the destination and
        // can be redirected after validation.
        'shortener_domains' => [
            'bit.ly', 'tinyurl.com', 't.co', 'is.gd', 'v.gd', 'ow.ly',
            'buff.ly', 'cutt.ly', 'shorturl.at', 'rebrand.ly', 'tiny.cc',
            'bit.do', 'soo.gd', 's2r.co', 'clicky.me', 'budurl.com',
            'gg.gg', 'tr.im', 'shorte.st', 'clck.ru', 'urlz.fr',
        ],
        // HTTP timeout of the periodic link check, in seconds.
        'check_timeout' => (int) env('DOLINEWS_LINK_CHECK_TIMEOUT', 10),
        // Ceiling on what one probe may download. Only a peer declaring
        // its length is stopped by it, which is why the timeout above
        // stays the real bound.
        'check_max_bytes' => (int) env('DOLINEWS_LINK_CHECK_MAX_BYTES', 262144),
    ],

    // Browser-side headers (see SecurityHeaders).
    'security' => [
        // HSTS lifetime in seconds, announced in production over https
        // only. A year is the value that gets a domain preloaded.
        'hsts_seconds' => (int) env('DOLINEWS_HSTS_SECONDS', 31536000),
    ],

    // Generic feeds (SPEC 6.4): bounded cache so every filter combination
    // cannot hammer the database.
    'feeds' => [
        'cache_seconds' => (int) env('DOLINEWS_FEEDS_CACHE_SECONDS', 300),
        // Articles per feed document.
        'page_size' => (int) env('DOLINEWS_FEEDS_PAGE_SIZE', 50),
    ],

    // Bootstrap super admin account, seeded from the environment
    // (SPEC 4.1/5.1). Change the password on first login.
    'super_admin' => [
        'email' => env('DOLINEWS_SUPER_ADMIN_EMAIL', 'admin@dolinews.invalid'),
        'name' => env('DOLINEWS_SUPER_ADMIN_NAME', 'Super administrateur DoliNews'),
        'password' => env('DOLINEWS_SUPER_ADMIN_PASSWORD'),
    ],

    // Dolibarr major left in a module descriptor by the module builder
    // (need_dolibarr_version = array(11, -3) in its template). Nobody
    // edits it, so an announcement carrying this floor and nothing else
    // states a version its own author never checked: the service says
    // what was announced (D1), and this was never announced. Read by the
    // views, which drop the bound, and by the catalogue script, which
    // does not submit it in the first place.
    'dolibarr_generator_default_min' => 11,

    // Interface locales (SPEC D14). French is the source: its strings
    // are the translation keys, so it needs no lang file. The others
    // are the most active Dolibarr communities after France and the
    // English-speaking world; an incomplete file falls back to English,
    // which is why LocalesTest requires every key in every language.
    'locales' => ['fr', 'en', 'es', 'de', 'it', 'pt', 'nl', 'pl', 'ro', 'el'],

    // Endonyms shown in the language switch. A language is named in its
    // own language: a reader looking for English does not read "Anglais".
    'locale_names' => [
        'fr' => 'Français',
        'en' => 'English',
        'es' => 'Español',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'ro' => 'Română',
        'el' => 'Ελληνικά',
    ],

    // Article locales accepted for content (BCP-47 style, e.g. fr_FR),
    // kept aligned with the interface set: a reader who browses the
    // service in their language must be able to submit in it too.
    'content_locales' => [
        'fr_FR', 'en_US', 'es_ES', 'de_DE', 'it_IT', 'pt_PT', 'nl_NL',
        'pl_PL', 'ro_RO', 'el_GR',
    ],

    // Licence of the published contents (SPEC D15). Share-alike requires
    // every redistributed copy to name it, so the feeds carry it too and
    // read it here rather than repeating the string.
    'content_license' => [
        'name' => 'CC BY-SA 4.0',
        'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
    ],

    // Public repository of the service (SPEC D13). The AGPL binds the
    // operator to offer the source to the users of the service, so the
    // licence mention has to carry the address: naming a licence without
    // saying where the code is leaves the obligation unserved.
    'source_url' => 'https://github.com/dolismartmaker/dolinews',
];
