<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SyvLaunchSortWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $details */
    public function __construct(public readonly array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Launched product still needs Shop Your Vibe sorting');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.syv-launch-sort-warning');
    }
}
