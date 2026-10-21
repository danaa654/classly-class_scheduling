<?php

namespace App\Services;

use App\Jobs\SendFacultyInviteJob;
use App\Models\Faculty;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FACULTY LOGIN ACCOUNTS — the single place that creates a login for a
 * Faculty Master record, links it, and issues "set your password"
 * invitations. Controllers (FacultyAccountController) and the invite
 * job only orchestrate; the rules live here so single-create, bulk
 * generate and resend can never disagree.
 *
 * SECURITY MODEL
 *  - The account is created with a random password nobody knows. The
 *    faculty member sets their own through a signed, expiring link;
 *    no one (Admin, Registrar, Dean) ever sees or types a password.
 *  - The link comes from a dedicated password broker
 *    ("faculty_invites", config/auth.php) so invitations can live 72
 *    hours without lengthening the normal 60-minute Forgot Password
 *    link.
 *  - createFor() only ever assigns the Faculty role. System roles
 *    (Registrar / Dean / OIC / Assistant Dean) are applied separately by
 *    applySystemRoles(), which the Administrator-only faculty edit form
 *    calls — never from any Dean/OIC/Registrar-facing request.
 */
class FacultyAccountService
{
    public const ROLE = 'Faculty';

    public const BROKER = 'faculty_invites';

    /** System roles an Administrator may layer on top of a faculty login. Administrator is deliberately NOT here. */
    public const ASSIGNABLE_ROLES = ['Registrar', 'Assistant Dean', 'Dean', 'OIC'];

    /** Of those, the roles that are College-bound (need users.college_id). */
    public const COLLEGE_ROLES = ['Dean', 'OIC'];

    /** Reasons a faculty row is skipped (value = text shown to the user). */
    public const SKIP_REASONS = [
        'has_account' => 'Already has an account',
        'inactive' => 'Faculty record is Inactive',
        'no_email' => 'No email on file',
        'email_in_use' => 'Email already belongs to another account',
    ];

    /**
     * Faculty rows $actor may provision, mirroring
     * FacultyPolicy::provisionAccount() in SQL so the "N pending"
     * count on the button always equals what the bulk action will
     * actually try. (Each row is still re-authorized through the
     * policy before anything is written.)
     *
     * @return Builder<Faculty>
     */
    public function provisionableQuery(User $actor): Builder
    {
        $query = Faculty::query()
            ->visibleTo($actor)
            ->where('status', 'Active')
            ->whereNull('user_id');

        if (AccessScope::isUnrestricted($actor)) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($actor) {
            // GenEd/Minor faculty (no College) — Assistant Dean's lane.
            if (AccessScope::isAssistantDean($actor)) {
                $scope->orWhereNull('college_id');
            }

            // A Dean/OIC's own College only.
            if (AccessScope::isCollegeScoped($actor) && ! AccessScope::hasNoAssignedCollege($actor)) {
                $scope->orWhere('college_id', $actor->college_id);
            }

            // Neither lane applies -> match nothing.
            if (! AccessScope::isAssistantDean($actor) && ! AccessScope::isCollegeScoped($actor)) {
                $scope->whereRaw('1 = 0');
            }
        });
    }

    /**
     * How many rows the bulk button would create vs. have to skip
     * for lack of an email.
     *
     * @return array{pending: int, missingEmail: int}
     */
    public function summaryFor(User $actor): array
    {
        $base = $this->provisionableQuery($actor);

        $withEmail = (clone $base)->whereNotNull('email')->where('email', '!=', '')->count();
        $total = (clone $base)->count();

        return ['pending' => $withEmail, 'missingEmail' => $total - $withEmail];
    }

    /**
     * Create (and link) the login for one faculty member. Never
     * throws for an expected problem — returns the reason instead, so
     * a bulk run can report skips row by row.
     *
     * @return array{user: ?User, skipped: ?string}  skipped = a SKIP_REASONS key
     */
    public function createFor(Faculty $faculty, ?string $unusablePasswordHash = null): array
    {
        if ($faculty->user_id) {
            return ['user' => null, 'skipped' => 'has_account'];
        }

        if ($faculty->status !== 'Active') {
            return ['user' => null, 'skipped' => 'inactive'];
        }

        $email = Str::lower(trim((string) $faculty->email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['user' => null, 'skipped' => 'no_email'];
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return ['user' => null, 'skipped' => 'email_in_use'];
        }

        // One bcrypt per batch is plenty: nobody knows the plaintext, so
        // sharing the hash grants nothing, and hashing per row would make a
        // 100-faculty run take long enough to hit PHP's execution limit.
        $hash = $unusablePasswordHash ?? Hash::make(Str::random(64));

        try {
            $user = DB::transaction(function () use ($faculty, $email, $hash) {
                // forceFill: password_changed_at / email_verified_at aren't in
                // User::$fillable, and must not be silently dropped.
                $user = new User;
                $user->forceFill([
                    'name' => trim("{$faculty->first_name} {$faculty->last_name}"),
                    'first_name' => $faculty->first_name,
                    'middle_name' => $faculty->middle_name,
                    'last_name' => $faculty->last_name,
                    'suffix' => $faculty->suffix,
                    'email' => $email,
                    'password' => $hash,
                    'password_changed_at' => now(),
                    'status' => 'Active',
                    // college_id intentionally left null: users.college_id is
                    // Dean/OIC scope data. A Faculty login must never pick up
                    // any College scope by accident.
                    'college_id' => null,
                    'email_verified_at' => now(),
                ])->save();

                $user->assignRole(self::ROLE);

                $faculty->forceFill(['user_id' => $user->id])->saveQuietly();

                return $user;
            });
        } catch (QueryException $e) {
            // Lost a race on the unique email index.
            return ['user' => null, 'skipped' => 'email_in_use'];
        }

        return ['user' => $user, 'skipped' => null];
    }

    /**
     * The non-Faculty role names currently on an account.
     *
     * @return list<string>
     */
    public function systemRolesOf(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->roles->pluck('name')->reject(fn (string $name) => $name === self::ROLE)->values()->all();
    }

    /**
     * An EXISTING, not-yet-linked login that shares this faculty member's
     * email (e.g. Mr. Polo already has an OIC account). Rather than refusing
     * with "email already in use", the faculty edit form links it so one
     * person keeps one login.
     */
    public function linkableUserFor(Faculty $faculty): ?User
    {
        if ($faculty->user_id) {
            return null;
        }

        $email = Str::lower(trim((string) $faculty->email));

        if ($email === '') {
            return null;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || Faculty::query()->where('user_id', $user->id)->exists()) {
            return null;
        }

        return $user;
    }

    /**
     * Make $roles the person's system roles (Faculty is always kept).
     * Creates the login if there is none (or links an existing one with
     * the same email), then syncs roles + College/Department scope.
     * An empty $roles list strips every system role again, leaving a
     * plain Faculty login. Never touches an Administrator account's roles.
     *
     * Call inside a transaction. Throws ValidationException on `system_roles`.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $departmentIds
     * @return array{user: ?User, created: bool, linked: bool}
     */
    public function applySystemRoles(Faculty $faculty, array $roles, ?int $collegeId, array $departmentIds, bool $overseesAll): array
    {
        $roles = array_values(array_intersect(self::ASSIGNABLE_ROLES, $roles));
        $user = $faculty->user_id ? User::query()->find($faculty->user_id) : null;
        $created = false;
        $linked = false;

        if (! $user) {
            if ($roles === []) {
                return ['user' => null, 'created' => false, 'linked' => false];
            }

            $user = $this->linkableUserFor($faculty);

            if ($user) {
                $faculty->forceFill(['user_id' => $user->id])->saveQuietly();
                $linked = true;
            } else {
                $result = $this->createFor($faculty);

                if ($result['skipped']) {
                    throw ValidationException::withMessages([
                        'system_roles' => "Can't create a login for {$faculty->full_name}: ".self::SKIP_REASONS[$result['skipped']].'.',
                    ]);
                }

                $user = $result['user'];
                $created = true;
            }
        }

        if ($user->hasRole('Administrator')) {
            if ($roles !== []) {
                throw ValidationException::withMessages([
                    'system_roles' => "This person's login is an Administrator account. Manage its roles from Users instead.",
                ]);
            }

            return ['user' => $user, 'created' => $created, 'linked' => $linked];
        }

        $scoped = array_intersect($roles, self::COLLEGE_ROLES) !== [];

        $user->syncRoles([self::ROLE, ...$roles]);

        $user->forceFill([
            'college_id' => $scoped ? $collegeId : null,
            'is_gened_assistant_dean' => $scoped ? (bool) $user->is_gened_assistant_dean : false,
        ])->save();

        $user->departments()->sync(in_array('OIC', $roles, true) && ! $overseesAll ? $departmentIds : []);

        return ['user' => $user, 'created' => $created, 'linked' => $linked];
    }

    /**
     * Queue the invitation email. The token is generated inside the
     * job (see SendFacultyInviteJob), so no usable secret ever sits in
     * the jobs table.
     */
    public function queueInvite(User $user, ?User $sentBy = null): void
    {
        $user->forceFill(['invite_error' => null])->saveQuietly();

        SendFacultyInviteJob::dispatch($user->id, $sentBy?->id);
    }

    /**
     * A fresh invitation URL. Creating a token replaces any earlier
     * unused one for the same email, so an older emailed link stops
     * working once a newer one is issued.
     */
    public function inviteUrl(User $user): string
    {
        $token = Password::broker(self::BROKER)->createToken($user);

        return route('invite.accept', ['token' => $token, 'email' => $user->email]);
    }

    /**
     * Account state for one faculty row, for the Faculty page badge.
     *
     * @return array{status: string, label: string}
     */
    public function stateFor(Faculty $faculty): array
    {
        $user = $faculty->relationLoaded('user') ? $faculty->user : $faculty->user()->first();

        if (! $user) {
            return ['status' => 'none', 'label' => 'No account'];
        }

        if ($user->status === 'Inactive') {
            return ['status' => 'inactive', 'label' => 'Deactivated'];
        }

        if ($user->invite_accepted_at) {
            return ['status' => 'active', 'label' => 'Active'];
        }

        if ($user->invite_error) {
            return ['status' => 'failed', 'label' => 'Send failed'];
        }

        if ($user->invited_at) {
            return ['status' => 'invited', 'label' => 'Invite sent'];
        }

        return ['status' => 'queued', 'label' => 'Invite queued'];
    }
}