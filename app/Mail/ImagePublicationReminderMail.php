<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ImagePublicationReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    public function __construct(public readonly array $products, public readonly string $kind) {}

    public function envelope(): Envelope
    {
        $prefix = $this->kind === 'follow_up' ? 'Follow-up' : 'Reminder';

        return new Envelope(subject: $prefix.': image and alt text changes need action');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.image-publication-reminder', with: [
            'products' => $this->products,
            'kind' => $this->kind,
            'instruction' => 'Open CMS → Products, paste the listed SKUs into the product filter, select the products, and complete the indicated approval, Shopify push or recovery action.',
        ]);
    }
}
