<div>
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
