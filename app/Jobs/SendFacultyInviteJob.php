<?php

namespace App\Jobs;

use App\Mail\FacultyInviteMail;
use App\Models\User;
use App\Services\FacultyAccountService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails one faculty member their "set your password" link. Always
 * queued (never sent inline) so generating accounts for a whole
 * College doesn't make the request wait on SMTP — same reason
 * SendFacultyScheduleEmailJob exists.
 *
 * The reset token is created HERE, when the job actually runs, not
 * when it's dispatched: the jobs table therefore never holds a usable
 * secret, and a retry always mails a fresh, valid link.
 */
class SendFacultyInviteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $userId,
        public ?int $sentByUserId = null,
    ) {}

    public function handle(FacultyAccountService $accounts): void
    {
        $user = User::with('faculty')->find($this->userId);

        // Gone, deactivated, or already finished setting up since this
        // was queued — nothing to send.
        if (! $user || $user->status !== 'Active' || $user->invite_accepted_at) {
            return;
        }

        try {
            $url = $accounts->inviteUrl($user);
            $sentBy = $this->sentByUserId ? User::find($this->sentByUserId) : null;

            // Same dedicated "gmail" mailer the Faculty Schedule emails use —
            // the default mailer is the Mailtrap sandbox, which never reaches
            // a real inbox. Override with FACULTY_INVITE_MAILER in .env (e.g.
            // "smtp" while demoing against Mailtrap).
            Mail::mailer(env('FACULTY_INVITE_MAILER', 'gmail'))
                ->to($user->email)
                ->send(new FacultyInviteMail($user, $url, $sentBy?->full_name));

            $user->forceFill(['invited_at' => now(), 'invite_error' => null])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('Faculty invite email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $user->forceFill(['invite_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
        }
    }
}