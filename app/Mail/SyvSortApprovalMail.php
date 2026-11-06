<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SyvSortApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $details */
    public function __construct(public readonly array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Shop Your Vibe sorting is awaiting approval');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.syv-sort-approval');
    }
}
