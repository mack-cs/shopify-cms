<div>
    @if($isSyncing ?? false)
        <div class="mb-3 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4"></circle>
                        <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <div>
                        <div class="font-semibold">Shopify sync in progress</div>
                        <div class="mt-1">
                            Products are still loading from Shopify. This list refreshes automatically every few seconds.
                            @if(!empty($syncStartedAt))
                                Last update {{ $syncStartedAt }}.
                            @endif
                        </div>
                    </div>
                </div>
                <x-filament::button
                    tag="a"
                    href="{{ $syncUrl }}"
                    color="primary"
                    outlined
                >
                    View Sync
                </x-filament::button>
            </div>
        </div>
    @endif

    @if($count > 0)
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-semibold">New products need sync</div>
                    <div class="mt-1">
                        {{ $count }} new product{{ $count === 1 ? '' : 's' }} were created in Shopify and are not yet in this list.
                        Run a sync to pull them into Products.
                    </div>
                </div>
                <x-filament::button
                    tag="a"
                    href="{{ $syncUrl }}"
                    color="warning"
                    outlined
                >
                    Go to Sync
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
