<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\AdminNotification;
use App\Services\ImageAltTextBackfillService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PublishApprovedImageAltTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public array $imageIds, public int $userId) {}

    public function handle(ImageAltTextBackfillService $service): void
    {
        $selected = Image::query()->active()->whereIn('id', $this->imageIds)->get();
        $eligible = $selected->filter(fn ($image) => $service->approvedUploadAlt($image) !== null)->count();
        $review = $selected->count() - $eligible;
        $result = $service->publishIds($this->imageIds);
        $failed = $result['shopify_failed'];
        AdminNotification::sendToUserId(Notification::make()->title($failed || ! $eligible || $review ? 'Alt publication needs review' : 'Alt publication verified')
            ->body($failed ? collect($failed)->map(fn ($row) => 'Image '.$row['image_id'].': '.$row['reason'])->implode(' | ')
                : (! $eligible ? 'No selected image has current approval evidence. Review the image before publishing.' : $result['published'].' update(s) verified; already-current images were left unchanged. '.$review.' selected image(s) still require approval or identity review.'))
            ->status($failed || ! $eligible || $review ? 'warning' : 'success'), $this->userId);
    }
}
