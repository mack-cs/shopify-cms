<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class Url404ReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, array<string, mixed>> $results */
    public function __construct(public readonly array $results)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Monthly 404 URL report');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.url-404-report',
            with: ['results' => $this->results],
        );
    }
}
