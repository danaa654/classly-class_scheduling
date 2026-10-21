<?php

namespace App\Mail;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your CLASSLY account is ready" — carries ONLY the set-password
 * link, never a password.
 */
class FacultyInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url,
        public ?string $sentByName = null,
    ) {}

    public function envelope(): Envelope
    {
        // Explicit From: Gmail's SMTP rejects a From that doesn't match the
        // authenticated account (same reason FacultyScheduleMail sets it).
        // Falls back to the app default when GMAIL_USERNAME isn't set, e.g.
        // when FACULTY_INVITE_MAILER points at Mailtrap.
        $from = env('GMAIL_USERNAME') ?: config('mail.from.address');

        return new Envelope(
            from: new Address($from, env('MAIL_FROM_NAME', config('app.name'))),
            subject: 'Your CLASSLY account is ready — set your password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.faculty-invite',
            with: [
                'name' => $this->user->first_name ?: $this->user->name,
                'url' => $this->url,
                'hours' => (int) (config('auth.passwords.'.\App\Services\FacultyAccountService::BROKER.'.expire', 4320) / 60),
                'sentByName' => $this->sentByName,
                'schoolName' => app(SettingsService::class)->group('general')['general.school_name']
                    ?: config('app.name', 'Classly'),
            ],
        );
    }
}