<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class StaleStackReservationAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(public readonly array $rows) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: stale stack reservations need a manual fix',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stale-stack-reservation-alert',
            with: [
                'rows' => $this->rows,
            ],
        );
    }
}
