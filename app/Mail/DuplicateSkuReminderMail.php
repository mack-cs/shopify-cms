<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class DuplicateSkuReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array<string, mixed>> $conflicts
     */
    public function __construct(
        public readonly array $conflicts,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: duplicate product SKUs detected',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.duplicate-sku-reminder',
            with: [
                'conflicts' => $this->conflicts,
            ],
        );
    }
}
