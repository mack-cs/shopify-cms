<x-filament-widgets::widget>
    <div wire:poll.5s>
        @if($exports->isNotEmpty())
            <x-filament::section heading="Alt text exports">
                <div class="space-y-3">
                    @foreach($exports as $export)
                        <div wire:key="alt-export-{{ $export->id }}" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <div class="min-w-0">
                                <span class="font-semibold">{{ $export->created_at->format('d M H:i') }} — {{ ucfirst($export->status) }}</span>
                                <span>{{ $export->processed }} / {{ $export->total }} images processed</span>
                                @if($export->status === 'processing' && !$export->snapshot_at)
                                    <span>Resolving Shopify collection membership and image snapshot.</span>
                                @endif
                                @if($export->download_failed)
                                    <p class="text-warning-600">{{ $export->download_failed }} photographs could not be downloaded. See manifest.csv in the ZIP for each failure.</p>
                                @endif
                                @if($export->error)
                                    <p class="break-words text-danger-600">{{ $export->error }}</p>
                                @endif
                            </div>
                            @if($export->status === 'completed')
                                <x-filament::button tag="a" size="sm" href="{{ route('image-alt-text-exports.download', $export) }}">Download</x-filament::button>
                                @if($export->download_failed)
                                    <x-filament::button size="sm" color="gray" wire:click="retryExport('{{ $export->id }}')" wire:loading.attr="disabled">Retry missing photographs</x-filament::button>
                                @endif
                            @elseif($export->status === 'failed')
                                <x-filament::button size="sm" color="gray" wire:click="retryExport('{{ $export->id }}')" wire:loading.attr="disabled">Retry</x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-sm text-gray-500">Exports expire after {{ config('alt_text_exports.retention_hours', 24) }} hours. Downloads serve the saved file.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
