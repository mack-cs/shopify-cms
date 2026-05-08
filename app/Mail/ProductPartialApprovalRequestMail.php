<?php

namespace App\Mail;

use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ProductPartialApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProductPartialApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var array<int, string>
     */
    public array $requestedFieldLabels;

    /**
     * @param array<int, ProductPartialApprovalRequest> $requests
     */
    public function __construct(
        public readonly User $requester,
        public readonly array $requests,
        public readonly ?User $targetApprover,
        public readonly string $queueUrl,
        ProductPartialApprovalService $service,
    ) {
        $firstRequest = $this->requests[0] ?? null;

        $this->requestedFieldLabels = $firstRequest instanceof ProductPartialApprovalRequest
            ? $service->requestFieldLabels(
                is_array($firstRequest->scopes) ? $firstRequest->scopes : [],
                is_array($firstRequest->core_fields) ? $firstRequest->core_fields : [],
            )
            : [];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Approval request from ' . ($this->requester->name ?: 'Requester'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.product-partial-approval-request',
            with: [
                'requester' => $this->requester,
                'requests' => $this->requests,
                'targetApprover' => $this->targetApprover,
                'queueUrl' => $this->queueUrl,
                'requestedFieldLabels' => $this->requestedFieldLabels,
            ],
        );
    }

    public function build(): static
    {
        return $this->from(
            config('mail.from.address'),
            $this->requester->name ?: config('mail.from.name')
        )->replyTo(
            $this->requester->email,
            $this->requester->name ?: null
        );
    }
}
