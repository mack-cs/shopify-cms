<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Enums\PermissionEnum;
use App\Models\ImageAltTextExport;
use App\Services\ImageAltTextExportService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class AltTextExportStatus extends Widget
{
    protected static string $view = 'filament.widgets.alt-text-export-status';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false;
    }

    public function retryExport(string $id): void
    {
        app(ImageAltTextExportService::class)->retry(Auth::user(), ImageAltTextExport::findOrFail($id));
        Notification::make()->title('Alt text export queued for retry')->success()->send();
    }

    protected function getViewData(): array
    {
        return ['exports' => ImageAltTextExport::where('user_id', Auth::id())->where('expires_at', '>', now())
            ->latest()->limit(5)->get()];
    }
}
