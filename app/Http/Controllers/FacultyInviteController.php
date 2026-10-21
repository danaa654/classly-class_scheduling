<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\FacultyAccountService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FACULTY LOGIN ACCOUNTS — the page a faculty member lands on from the
 * emailed (or hand-delivered) invitation link, where they choose their
 * own password. Guest-only; the token itself is the credential.
 *
 * Separate from NewPasswordController (Forgot Password) on purpose:
 * invitations use their own 72-hour broker, the new password must meet
 * the Settings > Security password policy, and finishing it stamps the
 * account as set up (invite_accepted_at) so the Faculty page can show
 * "Active".
 */
class FacultyInviteController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $policy,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Request $request): Response
    {
        $token = (string) $request->route('token');
        $user = User::query()->where('email', (string) $request->query('email'))->first();

        return Inertia::render('Auth/AcceptInvite', [
            'email' => (string) $request->query('email'),
            'token' => $token,
            // Don't say WHY it's invalid (no such account / expired / used) —
            // one message covers all of them.
            'valid' => $this->isUsable($user) && Password::broker(FacultyAccountService::BROKER)->tokenExists($user, $token),
            'policyDescription' => $this->policy->description(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', ...$this->policy->rules()],
        ]);

        $user = User::query()->where('email', $request->string('email'))->first();

        // password_reset_tokens is shared with Forgot Password, so a token
        // for a Dean's 60-minute reset would also pass this broker's longer
        // expiry. Only an Active Faculty-role account that hasn't finished
        // setting up may use this endpoint.
        if (! $this->isUsable($user)) {
            throw ValidationException::withMessages([
                'email' => ['This invitation link is no longer valid. Ask the Registrar\'s office to send a new one.'],
            ]);
        }

        $status = Password::broker(FacultyAccountService::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => $request->string('password')->toString(), // hashed by the model cast
                    'password_changed_at' => now(),
                    'must_change_password' => false,
                    'invite_accepted_at' => now(),
                    'invite_error' => null,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->activityLog->record(
                    ActivityLogService::FACULTY_ACCOUNT_ACTIVATED,
                    "{$user->full_name} set their password and activated their faculty account.",
                    $user->faculty,
                    $user,
                );
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Your password is set. You can now log in.');
        }

        throw ValidationException::withMessages([
            'email' => ['This invitation link is no longer valid. Ask the Registrar\'s office to send a new one.'],
        ]);
    }

    private function isUsable(?User $user): bool
    {
        return $user !== null
            && $user->status === 'Active'
            && $user->hasRole(FacultyAccountService::ROLE)
            && $user->invite_accepted_at === null;
    }
}