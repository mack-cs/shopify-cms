<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class MissingAltTextReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, array<string, mixed>> $products */
    public function __construct(public readonly array $products)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Monthly missing image alt text report');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.missing-alt-text-report',
            with: ['products' => $this->products],
        );
    }
}
