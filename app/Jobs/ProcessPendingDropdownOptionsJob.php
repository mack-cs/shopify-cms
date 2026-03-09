<?php

namespace App\Jobs;

use App\Filament\Resources\PendingDropdownOptionResource;
use App\Models\DropdownOption;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPendingDropdownOptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $recordIds
     */
    public function __construct(
        private readonly array $recordIds,
        private readonly string $operation,
        private readonly ?int $userId = null,
    ) {
    }

    public function handle(): void
    {
        $records = DropdownOption::query()
            ->whereIn('id', $this->recordIds)
            ->get();

        if ($records->isEmpty()) {
            $this->notify('Pending dropdown processing', 'No rows found to process.', true);
            return;
        }

        $handled = [];
        $processed = 0;

        foreach ($records as $record) {
            $key = strtolower(trim((string) $record->header) . '|' . trim((string) $record->value));
            if ($key === '|' || isset($handled[$key])) {
                continue;
            }
            $handled[$key] = true;

            if ($this->operation === 'approve') {
                PendingDropdownOptionResource::approveForApplicableCollections($record, $this->userId);
                $processed++;
                continue;
            }

            if ($this->operation === 'reject') {
                PendingDropdownOptionResource::rejectForApplicableCollections($record, $this->userId);
                $processed++;
            }
        }

        $label = $this->operation === 'approve' ? 'approved' : 'rejected';
        $this->notify(
            'Pending dropdown processing complete',
            "Processed {$processed} unique values ({$label}).",
            true
        );
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        $notification = $success ? $notification->success() : $notification->danger();
        $notification->sendToDatabase($user);
    }
}

