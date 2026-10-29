<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Models\ProductLaunchRun;
use App\Services\Shopify\ProductLaunchService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

final class ProductLaunchResult extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'product-launches';

    protected static ?string $title = 'Product launch';

    protected static string $view = 'filament.pages.product-launch-result';

    public ?ProductLaunchRun $launch = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->can(PermissionEnum::ShopifyPushProducts->value) ?? false, 403);
        $runId = (int) request()->query('run');
        $this->launch = $runId > 0
            ? ProductLaunchRun::query()->with(['items.product', 'user'])->find($runId)
            : null;
    }

    public function retryLaunch(): void
    {
        abort_unless(Auth::user()?->can(PermissionEnum::ShopifyPushProducts->value), 403);
        if ($this->launch !== null) {
            app(ProductLaunchService::class)->retry($this->launch, (int) Auth::id());
            $this->refreshLaunch();
        }
    }

    public function refreshLaunch(): void
    {
        if (! $this->launch instanceof ProductLaunchRun) {
            return;
        }

        $this->launch = $this->launch->fresh(['items.product', 'user']);
    }
}
