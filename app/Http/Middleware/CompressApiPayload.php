<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PERFORMANCE — compress API payloads.
 *
 * Most web servers (Nginx/Apache) already gzip responses when
 * configured to, but local `php artisan serve` / some shared hosts
 * don't, and it's easy for that server-level setting to quietly go
 * missing during a deploy. This middleware guarantees JSON/HTML/text
 * responses over a minimum size are gzip-compressed at the
 * application layer regardless of server config, for any client that
 * says it accepts gzip (every browser and axios/fetch do, by default).
 *
 * This INCLUDES Inertia page visits (X-Inertia XHRs). Those are the
 * biggest JSON payloads the app sends — every sidebar click returns
 * the whole page's props (section lists, faculty rosters, room
 * summaries, ...) — and gzip typically shrinks that JSON by 80-90%.
 * The browser decodes Content-Encoding: gzip transparently before
 * Inertia ever sees the body, so nothing on the client changes.
 *
 * Deliberately skipped: streamed/file downloads (PDF, CSV/XLSX
 * templates), redirects and empty responses, and anything that is
 * already Content-Encoding'd (e.g. by Nginx upstream).
 *
 * Registered in bootstrap/app.php:
 *   $middleware->append(\App\Http\Middleware\CompressApiPayload::class);
 */
class CompressApiPayload
{
    /** Don't bother compressing tiny payloads — gzip overhead isn't worth it below this. */
    private const MIN_BYTES_TO_COMPRESS = 860;

    /** Content types that compress well. */
    private const COMPRESSIBLE_TYPES = [
        'application/json',
        'application/javascript',
        'application/xml',
        'image/svg+xml',
        'text/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || strlen($content) < self::MIN_BYTES_TO_COMPRESS) {
            return $response;
        }

        $compressed = function_exists('gzencode') ? gzencode($content, 6) : false;

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if ($request->isMethod('HEAD')) {
            return false;
        }

        if (! str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return false;
        }

        // Streamed/file responses have no in-memory body to compress.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->isRedirection() || $response->isEmpty() || $response->getStatusCode() === 206) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false; // already compressed upstream
        }

        // Attachments (CSV exports etc.) keep their exact bytes.
        if (str_contains((string) $response->headers->get('Content-Disposition'), 'attachment')) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        foreach (self::COMPRESSIBLE_TYPES as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }
}