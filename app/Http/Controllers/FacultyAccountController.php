<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Services\ActivityLogService;
use App\Services\FacultyAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FACULTY LOGIN ACCOUNTS — create / bulk-generate / resend / copy-link,
 * run from the Faculty page by Administrator, Registrar, Dean/OIC and
 * Assistant Dean.
 *
 * AUTHORIZATION: every write goes through
 * FacultyPolicy::provisionAccount() (Admin/Registrar: any faculty;
 * Dean/OIC: their own College; Assistant Dean: GenEd/Minor faculty).
 * There is deliberately NO role field anywhere in these requests —
 * the only thing this controller can ever create is a Faculty-role
 * login, so it can't be used to mint or promote an Admin/Registrar/
 * Dean account. User Management stays Administrator-only.
 */
class FacultyAccountController extends Controller
{
    /** Roles that may use the Generate Accounts button at all (each row is still scope-checked). */
    private const PROVISIONING_ROLES = ['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC'];

    public function __construct(
        private readonly FacultyAccountService $accounts,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Create the account for ONE faculty member and email the invite.
     */
    public function store(Request $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('provisionAccount', $faculty);

        $result = $this->accounts->createFor($faculty);

        if ($result['skipped']) {
            return back()->with('error', "{$faculty->full_name}: ".FacultyAccountService::SKIP_REASONS[$result['skipped']].'.');
        }

        $user = $result['user'];

        $this->accounts->queueInvite($user, $request->user());

        $this->activityLog->record(
            ActivityLogService::FACULTY_ACCOUNT_CREATED,
            "Created a login account for faculty {$faculty->full_name} ({$user->email}) and sent the set-password invitation.",
            $faculty,
            $request->user(),
        );

        return back()->with('success', "Account created for {$faculty->full_name}. The set-password invitation is being sent to {$user->email}.");
    }

    /**
     * Create accounts for every faculty member in the actor's scope who
     * doesn't have one yet. Admin/Registrar: everyone. Dean/OIC: their
     * own College. Assistant Dean: GenEd/Minor faculty.
     */
    public function generate(Request $request): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($actor->hasAnyRole(self::PROVISIONING_ROLES), 403);

        $candidates = $this->accounts->provisionableQuery($actor)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        if ($candidates->isEmpty()) {
            return back()->with('error', 'Every faculty member in your scope already has an account.');
        }

        // One hash for the whole batch — see FacultyAccountService::createFor().
        $unusableHash = Hash::make(Str::random(64));

        $created = 0;
        $skipped = [];

        foreach ($candidates as $faculty) {
            // The query mirrors the policy, but never rely on that alone.
            if (! $actor->can('provisionAccount', $faculty)) {
                continue;
            }

            $result = $this->accounts->createFor($faculty, $unusableHash);

            if ($result['skipped']) {
                $skipped[] = [
                    'name' => $faculty->full_name,
                    'faculty_id' => $faculty->faculty_id,
                    'reason' => FacultyAccountService::SKIP_REASONS[$result['skipped']],
                ];

                continue;
            }

            $this->accounts->queueInvite($result['user'], $actor);
            $created++;
        }

        if ($created > 0 || $skipped !== []) {
            $this->activityLog->record(
                ActivityLogService::FACULTY_ACCOUNT_CREATED,
                "Bulk-generated faculty login accounts: {$created} created, ".count($skipped).' skipped.',
                null,
                $actor,
            );
        }

        $message = $created > 0
            ? "Created {$created} faculty account".($created === 1 ? '' : 's').' and queued the set-password invitations.'
            : 'No accounts were created.';

        if ($skipped !== []) {
            $message .= ' '.count($skipped).' skipped — see the list for why.';
        }

        // Cap what rides in the session; the message above still has the real count.
        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('accountSkips', array_slice($skipped, 0, 100));
    }

    /**
     * Email the invitation again (expired link, lost email, typo fixed).
     * Issuing a new link invalidates the previous unused one.
     */
    public function resend(Request $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('provisionAccount', $faculty);

        $user = $this->pendingUserOrNull($faculty);

        if (is_string($user)) {
            return back()->with('error', $user);
        }

        $this->accounts->queueInvite($user, $request->user());

        $this->activityLog->record(
            ActivityLogService::FACULTY_INVITE_SENT,
            "Re-sent the set-password invitation to faculty {$faculty->full_name} ({$user->email}).",
            $faculty,
            $request->user(),
        );

        return back()->with('success', "A new invitation is being sent to {$user->email}.");
    }

    /**
     * Fallback when email isn't available: hand the person a one-time
     * link to pass on themselves (chat, in person). Same set-your-own-
     * password flow, so it's still safer than sharing a password.
     * Creating it invalidates any earlier unused link.
     */
    public function inviteLink(Request $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('provisionAccount', $faculty);

        $user = $this->pendingUserOrNull($faculty);

        if (is_string($user)) {
            return back()->with('error', $user);
        }

        $url = $this->accounts->inviteUrl($user);

        $this->activityLog->record(
            ActivityLogService::FACULTY_INVITE_SENT,
            "Generated a one-time set-password link for faculty {$faculty->full_name} ({$user->email}).",
            $faculty,
            $request->user(),
        );

        return back()->with('inviteLink', ['faculty' => $faculty->full_name, 'url' => $url]);
    }

    /**
     * The faculty's linked, still-pending user — or a message string
     * explaining why there isn't one.
     */
    private function pendingUserOrNull(Faculty $faculty): \App\Models\User|string
    {
        $user = $faculty->user;

        if (! $user) {
            return "{$faculty->full_name} doesn't have an account yet. Create one first.";
        }

        if ($user->status !== 'Active') {
            return "{$faculty->full_name}'s account is deactivated.";
        }

        if ($user->invite_accepted_at) {
            return "{$faculty->full_name} has already set a password. They can use \"Forgot password?\" on the login page if they need a new one.";
        }

        return $user;
    }
}