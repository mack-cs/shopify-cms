<?php

namespace App\Console\Commands;

use App\Services\ImagePublicationReminderService;
use Illuminate\Console\Command;

class SendImagePublicationReminders extends Command
{
    protected $signature = 'images:remind-publication';

    protected $description = 'Email one reminder for image and alt text changes outstanding for more than 90 minutes.';

    public function handle(ImagePublicationReminderService $reminders): int
    {
        $result = $reminders->sendDue();
        $this->line(json_encode($result));

        return $result['error'] ? self::FAILURE : self::SUCCESS;
    }
}
