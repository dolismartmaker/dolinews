<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Read surface of the public API (SPEC 5.2): generous, per IP when
        // anonymous, per account when tokened.
        RateLimiter::for('api-read', function (Request $request): Limit {
            $user = $request->user();

            if ($user !== null) {
                return Limit::perMinute(120)->by('user:'.(string) $user->getAuthIdentifier());
            }

            return Limit::perMinute(60)->by('ip:'.(string) $request->ip());
        });

        // Write points of the public API (SPEC 5.2): tighter, since every
        // submission lands in a human-reviewed queue (SPEC 5.1).
        RateLimiter::for('api-write', function (Request $request): Limit {
            $user = $request->user();

            if ($user !== null) {
                return Limit::perMinute(10)->by('user:'.(string) $user->getAuthIdentifier());
            }

            return Limit::perMinute(5)->by('ip:'.(string) $request->ip());
        });

        // Authentication endpoints: brute-force guard.
        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by('ip:'.(string) $request->ip().':'.$request->input('email', ''));
        });

        // Contribution qualification (SPEC 3.2): starting the flow mails
        // a possession code to an address taken from the public
        // committer index - somebody else's address. Bounded twice, by
        // account and by origin, so neither a single account nor a
        // single host can turn it into a mail bomber.
        RateLimiter::for('qualify', function (Request $request): array {
            $user = $request->user();

            return [
                Limit::perHour((int) config('dolinews.verification.attempts_per_account', 5))
                    ->by('qualify:user:'.(string) ($user?->getAuthIdentifier() ?? 'guest')),
                Limit::perHour((int) config('dolinews.verification.attempts_per_ip', 15))
                    ->by('qualify:ip:'.(string) $request->ip()),
            ];
        });
    }
}
