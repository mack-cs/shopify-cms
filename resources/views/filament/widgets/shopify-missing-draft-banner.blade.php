<div>
    @if (($count ?? 0) > 0)
        <x-filament-widgets::widget>
            <x-filament::section>
                <div class="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-900">
                    {{ $count }} draft recovery record(s) were removed from Shopify but still exist locally.
                    Clean the local product or investigate before enabling recovery. These drafts are blocked from automatic re-sync until you explicitly enable recovery.
                </div>
            </x-filament::section>
        </x-filament-widgets::widget>
    @endif
</div>
