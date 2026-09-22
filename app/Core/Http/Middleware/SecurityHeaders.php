<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defence in depth.
 *
 * The service renders HTML built from contributor Markdown and serves
 * images contributors uploaded. The renderer is blindfolded (tag and
 * attribute allow-lists, unsafe links refused) and that is the real
 * defence; these headers are the second line, for the day a hole opens
 * in the first.
 *
 * Two policies, because the two surfaces are not the same shape. The
 * public surface, the one carrying third-party content, has no inline
 * script and no inline style at all, so it gets the strict policy. The
 * back-office runs Livewire and Alpine, which evaluate expressions and
 * inject their own bootstrap inline: it needs 'unsafe-inline' and
 * 'unsafe-eval'. That surface is reachable by moderators only, and it
 * renders no third-party markup.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('Content-Security-Policy', $this->policyFor($request));
        $headers->set('X-Content-Type-Options', 'nosniff');
        // frame-ancestors covers modern browsers; this covers the rest.
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The service reads no sensor and no device: the public pages
        // carry no JavaScript at all, and the back-office only draws
        // forms. Saying so costs one header and closes the question for
        // anything that ends up embedded in a page one day.
        $headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), '.
            'magnetometer=(), microphone=(), payment=(), usb=()'
        );

        // Announcing HSTS over plain HTTP pins nothing and, in local
        // development, would pin localhost to https for months.
        if ($request->secure() && app()->environment('production')) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('dolinews.security.hsts_seconds', 31536000).'; includeSubDomains'
            );
        }

        return $response;
    }

    /**
     * The policy the requested surface can actually run under.
     */
    private function policyFor(Request $request): string
    {
        $common = [
            "default-src 'self'",
            // Contributor images live on the local media disk, served
            // from this origin. data: covers the inline SVG-free icons
            // the stylesheet carries.
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            // Nothing outside is ever embedded, and nothing here is
            // meant to be embedded elsewhere.
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        // With the Vite dev server running, the assets come from another
        // origin: refusing it would make the policy unusable to develop
        // under, and the policy that gets switched off is worth nothing.
        $hot = Vite::isRunningHot() ? ' '.$this->hotOrigin() : '';

        if ($this->isBackOffice($request)) {
            return implode('; ', array_merge($common, [
                // Alpine compiles its expressions with new Function(),
                // and Livewire writes its bootstrap inline.
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$hot,
                "style-src 'self' 'unsafe-inline'".$hot,
            ]));
        }

        return implode('; ', array_merge($common, [
            "script-src 'self'".$hot,
            "style-src 'self'".$hot,
        ]));
    }

    /**
     * The origin the Vite dev server answers on, read from the hot file
     * it writes.
     */
    private function hotOrigin(): string
    {
        $hot = @file_get_contents(public_path('hot'));

        if (! is_string($hot)) {
            return '';
        }

        $url = parse_url(trim($hot));

        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            return '';
        }

        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
    }

    /**
     * Whether the request is served by the Livewire back-office. The
     * /livewire/* endpoints are its own, and answer its components.
     */
    private function isBackOffice(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'livewire/*');
    }
}
