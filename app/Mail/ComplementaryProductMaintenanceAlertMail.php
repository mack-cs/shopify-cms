<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ComplementaryProductMaintenanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{
     *   product:Product,
     *   local_total:int,
     *   local_eligible:int,
     *   shopify_eligible:int
     * }> $alerts
     */
    public function __construct(
        public readonly array $alerts,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Complementary product issues need attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.complementary-product-maintenance-alert',
            with: [
                'alerts' => $this->alerts,
            ],
        );
    }
}
