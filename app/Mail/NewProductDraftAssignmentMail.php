<?php

namespace App\Mail;

use App\Models\NewProductDraftAssignment;
use App\Services\NewProductDraftAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewProductDraftAssignmentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var array<int, string>
     */
    private array $columnLabels;

    public function __construct(
        public readonly NewProductDraftAssignment $assignment,
        NewProductDraftAssignmentService $service,
    ) {
        $this->columnLabels = array_map(
            fn (string $key): string => $service->labelForColumn($key),
            $assignment->selected_columns ?? []
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->assignment->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-product-draft-assignment',
            with: [
                'assignment' => $this->assignment,
                'columnLabels' => $this->columnLabels,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (!$this->assignment->csv_path) {
            return [];
        }

        return [
            Attachment::fromStorageDisk(
                $this->assignment->csv_disk,
                $this->assignment->csv_path
            )->as('new-product-draft-assignment.csv'),
        ];
    }

    public function build(): static
    {
        return $this->from(
            $this->assignment->from_email ?? config('mail.from.address'),
            $this->assignment->from_name ?? config('mail.from.name')
        );
    }
}
