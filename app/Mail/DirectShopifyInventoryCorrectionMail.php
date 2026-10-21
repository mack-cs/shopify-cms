<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class DirectShopifyInventoryCorrectionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{user:string,rows:array<int, array<string, mixed>>}>  $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly Carbon $periodStart,
        public readonly Carbon $periodEnd,
    ) {}

    public function envelope(): Envelope
    {
        $count = array_sum(array_map(fn (array $group): int => count($group['rows']), $this->groups));

        return new Envelope(
            subject: 'Direct Shopify inventory corrections, last 7 days ('.$count.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.direct-shopify-inventory-corrections',
            with: [
                'groups' => $this->groups,
                'fromDate' => $this->periodStart->timezone(config('app.timezone'))->format('M j, Y'),
                'untilDate' => $this->periodEnd->timezone(config('app.timezone'))->format('M j, Y'),
            ],
        );
    }
}
