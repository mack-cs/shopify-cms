<?php

namespace App\Mail;

use App\Models\ProcurementSupplierReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PendingSupplierReceiptPushReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ProcurementSupplierReceipt $receipt,
    ) {
    }

    public function envelope(): Envelope
    {
        $order = $this->receipt->line?->order?->order_number ?: 'supplier order';

        return new Envelope(
            subject: "Pending Shopify push for {$order}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pending-supplier-receipt-push-reminder',
            with: [
                'receipt' => $this->receipt,
            ],
        );
    }
}
