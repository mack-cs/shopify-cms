<div class="space-y-4 text-sm">
    @forelse($variant->inventoryAdjustmentRequestItems->sortByDesc(fn ($item) => $item->request?->submitted_at ?? $item->created_at) as $item)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold">{{ $item->request?->status ?? 'Unknown status' }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $item->request?->type ?? 'single' }}</span>
                @if($item->request?->original_filename)
                    <span class="text-gray-500 dark:text-gray-400">{{ $item->request->original_filename }}</span>
                @endif
            </div>
            <div class="mt-2 text-gray-600 dark:text-gray-300">
                Requested by {{ $item->request?->requester?->name ?? 'Unknown' }}
                for {{ $item->request?->approver?->name ?? 'Unknown approver' }}
                on {{ $item->request?->submitted_at?->format('d/m/Y H:i') ?? 'unknown date' }}.
            </div>
            <div class="mt-2">
                {{ $item->original_inventory_tracked ? ($item->original_on_hand_quantity ?? 'Unknown') : 'Not tracked' }}
                -&gt;
                {{ $item->requested_inventory_tracked ? ($item->requested_on_hand_quantity ?? 'Unknown') : 'Not tracked' }}
            </div>
            <div class="mt-2">{{ $item->reason }}</div>
            @if($item->request?->reviewed_at)
                <div class="mt-2 text-gray-600 dark:text-gray-300">
                    Reviewed by {{ $item->request?->reviewedBy?->name ?? 'Unknown' }}
                    on {{ $item->request->reviewed_at->format('d/m/Y H:i') }}.
                </div>
            @endif
            @if($item->request?->rejection_reason)
                <div class="mt-2 text-danger-600 dark:text-danger-400">{{ $item->request->rejection_reason }}</div>
            @endif
        </div>
    @empty
        <p>No inventory adjustments have been recorded for this SKU.</p>
    @endforelse
</div>
