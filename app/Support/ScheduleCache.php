<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * PERFORMANCE — one shared, self-invalidating cache for expensive
 * READ paths (Dashboard, Rooms page, Reports, the scheduling
 * recommendation endpoints).
 *
 * WHY A "GENERATION" INSTEAD OF PER-KEY forget() CALLS
 * ------------------------------------------------------------------
 * Every cached entry's key embeds the current generation token. Any
 * database WRITE to a table that scheduling data depends on (see
 * WATCHED_TABLES) rotates that token, so every entry cached under the
 * old token is instantly unreachable — no matter which controller,
 * service, queue job, or raw bulk ->update() made the write. That is
 * what makes this safe to bolt onto a codebase with hundreds of write
 * paths: nobody has to remember to call forget() after a save.
 *
 * The write detection lives in observeQuery(), fed by DB::listen() in
 * AppServiceProvider — it looks at the SQL itself, so query-builder
 * bulk updates/deletes (which never fire Eloquent model events) are
 * caught too.
 *
 * RACE SAFETY
 * ------------------------------------------------------------------
 * The token is rotated on the FIRST watched write of a request/job
 * (so readers stop using old entries immediately) and rotated AGAIN
 * when that request/job finishes (terminating callback / queue job
 * events). The second rotation discards anything another request
 * cached while this one's transaction was still uncommitted.
 *
 * Every entry also carries a TTL as a last-resort safety net.
 *
 * MULTI-SERVER NOTE
 * ------------------------------------------------------------------
 * The token lives in the application cache store, so with more than
 * one app server behind the load balancer (deploy/nginx-load-
 * balancer.conf) CACHE_STORE must be a SHARED store (redis or
 * database) — never `file`/`array`, which are per-server.
 */
class ScheduleCache
{
    private const GENERATION_KEY = 'schedule-cache.generation';

    /**
     * Tables whose contents change what the cached read paths return —
     * scheduling data, reference data the scheduling screens join
     * against, term/settings, and the user/role tables that decide
     * who is allowed to see what.
     *
     * @var list<string>
     */
    private const WATCHED_TABLES = [
        'section_subjects',
        'sections',
        'faculties',
        'faculty_subject',
        'faculty_load_requests',
        'rooms',
        'room_subject_recommendations',
        'subjects',
        'curriculums',
        'curriculum_items',
        'majors',
        'departments',
        'colleges',
        'academic_terms',
        'school_years',
        'semesters',
        'system_settings',
        'users',
        'department_user',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
    ];

    private static ?object $forApp = null;

    private static ?string $generation = null;

    private static bool $dirty = false;

    private static bool $terminatingRegistered = false;

    private static bool $bumping = false;

    /**
     * Cache the result of $callback under the current generation.
     *
     * A cache-store failure (e.g. the `cache` table not migrated yet)
     * never breaks the page — it just computes without caching.
     * Exceptions thrown by $callback itself always propagate.
     *
     * @param  array<int|string, mixed>  $keyParts  everything the result depends on besides DB state (user id, viewing term, query string, ...)
     */
    public static function remember(string $namespace, array $keyParts, int $ttlSeconds, Closure $callback): mixed
    {
        $key = self::keyFor($namespace, $keyParts);

        $hit = self::fetch($key);

        if (is_array($hit) && array_key_exists('value', $hit)) {
            return $hit['value'];
        }

        $value = $callback();

        self::store($key, ['value' => $value], $ttlSeconds);

        return $value;
    }

    /**
     * Build a cache key bound to the CURRENT generation. Returns null
     * when the cache store is unusable (callers then skip caching).
     *
     * @param  array<int|string, mixed>  $keyParts
     */
    public static function keyFor(string $namespace, array $keyParts): ?string
    {
        try {
            return sprintf(
                'schedule-cache:%s:%s:%s',
                $namespace,
                self::generation(),
                md5((string) json_encode($keyParts)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read a value stored with store(); null on a miss or store failure.
     */
    public static function fetch(?string $key): mixed
    {
        if ($key === null) {
            return null;
        }

        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write a value under a key from keyFor(). Never throws.
     */
    public static function store(?string $key, mixed $value, int $ttlSeconds): void
    {
        if ($key === null) {
            return;
        }

        try {
            Cache::put($key, $value, $ttlSeconds);
        } catch (Throwable) {
            // Caching is an optimisation only.
        }
    }

    /**
     * Current generation token (created on first use).
     */
    public static function generation(): string
    {
        self::syncWithApp();

        if (self::$generation !== null) {
            return self::$generation;
        }

        $token = Cache::get(self::GENERATION_KEY);

        if (! is_string($token) || $token === '') {
            $fresh = self::freshToken();
            Cache::add(self::GENERATION_KEY, $fresh);
            $stored = Cache::get(self::GENERATION_KEY);
            $token = is_string($stored) && $stored !== '' ? $stored : $fresh;
        }

        return self::$generation = $token;
    }

    /**
     * Invalidate every cached entry immediately.
     */
    public static function bump(): void
    {
        self::syncWithApp();

        if (self::$bumping) {
            return;
        }

        self::$bumping = true;

        try {
            $token = self::freshToken();
            self::$generation = $token;
            Cache::forever(self::GENERATION_KEY, $token);
        } catch (Throwable) {
            // If the store is down nothing is being cached either.
        } finally {
            self::$bumping = false;
        }
    }

    /**
     * Called for every executed SQL statement (DB::listen). Rotates the
     * generation when the statement writes to a watched table.
     */
    public static function observeQuery(QueryExecuted $query): void
    {
        $sql = ltrim($query->sql);

        if (strlen($sql) < 6) {
            return;
        }

        $verb = strtolower(substr($sql, 0, 6));

        if ($verb !== 'update' && $verb !== 'insert' && $verb !== 'delete' && $verb !== 'replac' && $verb !== 'trunca') {
            return;
        }

        if (! preg_match('/^(?:update|insert\s+(?:ignore\s+)?into|delete\s+from|replace\s+into|truncate\s+(?:table\s+)?)\s*[`"\[]?(\w+)/i', $sql, $matches)) {
            return;
        }

        if (! in_array(strtolower($matches[1]), self::WATCHED_TABLES, true)) {
            return;
        }

        self::noteWrite();
    }

    /**
     * Final rotation for a request/job that wrote to a watched table.
     * Wired to app terminating + queue job events in AppServiceProvider.
     */
    public static function flushPending(): void
    {
        self::syncWithApp();

        if (! self::$dirty) {
            return;
        }

        self::$dirty = false;
        self::bump();
    }

    private static function noteWrite(): void
    {
        self::syncWithApp();

        if (self::$dirty) {
            // Already rotated for this unit of work; the closing
            // rotation happens in flushPending().
            return;
        }

        self::$dirty = true;
        self::bump();

        if (! self::$terminatingRegistered) {
            self::$terminatingRegistered = true;
            app()->terminating(fn () => self::flushPending());
        }
    }

    /**
     * Static state must never leak from one application instance to the
     * next (test suite, Octane/queue workers that rebuild the app).
     */
    private static function syncWithApp(): void
    {
        $app = app();

        if (self::$forApp !== $app) {
            self::$forApp = $app;
            self::$generation = null;
            self::$dirty = false;
            self::$terminatingRegistered = false;
            self::$bumping = false;
        }
    }

    private static function freshToken(): string
    {
        return str_replace('.', '', sprintf('%.6F', microtime(true))).'-'.Str::lower(Str::random(6));
    }
}