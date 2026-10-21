<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FACULTY LOGIN ACCOUNTS — keeps a Faculty-role login inside its own
 * schedule page.
 *
 * Most existing authenticated routes (Dashboard, Chat, Notifications,
 * Reports, Settings...) are open to ANY logged-in user at the route
 * level and rely on each controller's own role checks. Rather than
 * trust that every one of them says no to a role that didn't exist
 * when they were written, this is an ALLOW-list: a user whose only
 * role is Faculty can reach nothing except the names below.
 *
 *  - Browser page loads (GET) are redirected to My Schedule, so typing
 *    /dashboard or /scheduling/faculty just lands them home.
 *  - Anything else (POST/PUT/DELETE, JSON/fetch calls) gets a 403.
 *
 * Users with ANY other role are untouched — this only ever restricts
 * accounts whose sole role is Faculty.
 */
class RestrictFacultyRole
{
    /** Route names a Faculty-only login may use. */
    private const ALLOWED = [
        'my-schedule',
        // Faculty can set / remove their own profile photo from My Schedule.
        'my-schedule.photo.update',
        'my-schedule.photo.destroy',
        'logout',
        // The forced-change screen (EnsurePasswordIsCurrent redirects here
        // when an admin flags the account or the password expires).
        'password.change',
        'password.change.update',
    ];

    private const OTHER_ROLES = ['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Faculty') || $user->hasAnyRole(self::OTHER_ROLES)) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('my-schedule');
        }

        abort(403);
    }
}