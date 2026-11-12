<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InventoryPipelineAlertMail extends Mailable
{
    public function __construct(public readonly array $details, public readonly string $type) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->details['environment'].'] Inventory pipeline '.$this->type.': '.$this->details['kind']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.inventory-pipeline-alert');
    }
}
