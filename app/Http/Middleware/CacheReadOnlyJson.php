<?php

namespace App\Http\Middleware;

use App\Support\ScheduleCache;
use App\Support\ViewingTerm;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PERFORMANCE — cache API responses.
 *
 * Server-side response cache for the expensive, READ-ONLY JSON
 * endpoints behind the scheduling screens (faculty/room/time
 * recommendations, busy-time lookups, room schedules, ...). Those
 * endpoints re-run the whole RecommendationService scoring pass every
 * time a dropdown or modal opens, even though the answer only changes
 * when the schedule itself changes.
 *
 * Attach per route:
 *
 *     Route::get(...)->middleware('cache.json:120');   // 120s safety TTL
 *
 * WHAT MAKES IT SAFE
 *   - GET only, and only successful (200) JsonResponse bodies are stored.
 *     Redirects, validation errors, 403/404s are never cached.
 *   - The key includes the authenticated user's id AND their Viewing
 *     Academic Term (a session value several services read), so one
 *     user's scoped/term-specific answer is never served to another.
 *   - The key also embeds ScheduleCache's generation, which rotates on
 *     ANY write to scheduling/reference/user/role tables — so a saved
 *     schedule change is visible on the very next request. The TTL is
 *     only a backstop.
 *   - Runs after `auth` and route-model binding, so guests and
 *     non-existent records never reach the cache.
 *
 * A response header `X-Schedule-Cache: HIT|MISS` is added so you can
 * confirm it in the browser's Network tab.
 */
class CacheReadOnlyJson
{
    public function handle(Request $request, Closure $next, string $ttlSeconds = '120'): Response
    {
        $user = $request->user();

        if (! $request->isMethod('GET') || ! $user) {
            return $next($request);
        }

        $key = ScheduleCache::keyFor('json', [
            'user' => $user->getAuthIdentifier(),
            'term' => ViewingTerm::resolve($request)?->id,
            'uri' => $request->getRequestUri(),
        ]);

        $cached = ScheduleCache::fetch($key);

        if (is_string($cached)) {
            return response($cached, 200, [
                'Content-Type' => 'application/json',
                'X-Schedule-Cache' => 'HIT',
            ]);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $content = $response->getContent();

            if (is_string($content) && $content !== '') {
                ScheduleCache::store($key, $content, max(1, (int) $ttlSeconds));
                $response->headers->set('X-Schedule-Cache', 'MISS');
            }
        }

        return $response;
    }
}