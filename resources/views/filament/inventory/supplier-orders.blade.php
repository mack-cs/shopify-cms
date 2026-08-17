<div class="space-y-4 text-sm">
    @forelse($variant->supplierOrderLines->sortByDesc('created_at') as $line)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="font-semibold">{{ $line->order?->order_number ?: 'Legacy order (ID not supplied)' }}</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">
                Ordered {{ $line->quantity_ordered }} · Received {{ $line->quantity_received }} · Outstanding {{ $line->quantity_outstanding }}
                · ETA {{ $line->eta_date?->format('d/m/Y') ?: 'not supplied' }} · {{ ucfirst($line->status) }}
            </div>
            @if($line->receipts->isNotEmpty())
                <div class="mt-3 border-t pt-2 dark:border-gray-700">
                    @foreach($line->receipts->sortByDesc('created_at') as $receipt)
                        <div>{{ $receipt->created_at?->format('d/m/Y H:i') }} — {{ $receipt->quantity_received }} received — {{ str_replace('_', ' ', ucfirst($receipt->status)) }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <p>No supplier orders have been recorded for this SKU.</p>
    @endforelse
</div>
