<div class="space-y-4 text-sm">
    <div>
        @php
            $lastAmendment = $order->amendments->sortByDesc('created_at')->first();
        @endphp
        <div class="font-semibold">{{ $order->order_number ?: 'Legacy order' }}</div>
        <div class="mt-1 text-gray-600 dark:text-gray-300">
            Created By:
            {{ $order->createdBy?->name ?: $order->createdBy?->email ?: '-' }}
            - Last Amended By:
            {{ $lastAmendment?->amendedBy?->name ?: $lastAmendment?->amendedBy?->email ?: '-' }}
        </div>
        <div class="mt-1 text-gray-600 dark:text-gray-300">
            Supplier:
            {{ $order->lines->pluck('variant.product.vendor')->filter()->unique()->implode(', ') ?: '-' }}
            - Ordered {{ $order->lines->sum('quantity_ordered') }}
            - Received {{ $order->lines->sum(fn ($line) => $line->quantity_received) }}
            - Outstanding {{ $order->lines->sum(fn ($line) => $line->quantity_outstanding) }}
        </div>
        @php
            $grvs = $order->lines->flatMap(fn ($line) => $line->receipts)->pluck('grv_number')->filter()->unique()->values();
        @endphp
        <div class="mt-1 text-gray-600 dark:text-gray-300">
            GRVs: {{ $grvs->isNotEmpty() ? $grvs->implode(', ') : '-' }}
        </div>
        <div class="mt-3">
            <a
                href="{{ route('inventory.supplier-orders.export', $order) }}"
                class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
            >
                Export CSV
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2 text-left">SKU</th>
                    <th class="py-2 text-left">Product</th>
                    <th class="py-2 text-left">Ordered</th>
                    <th class="py-2 text-left">Received</th>
                    <th class="py-2 text-left">Outstanding</th>
                    <th class="py-2 text-left">Status</th>
                    <th class="py-2 text-left">GRVs</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->lines->sortBy('sku') as $line)
                    <tr class="border-b align-top">
                        <td class="py-2">{{ $line->sku }}</td>
                        <td class="py-2">{{ $line->variant?->product?->title ?? $line->draft?->title ?? '-' }}</td>
                        <td class="py-2">{{ $line->quantity_ordered }}</td>
                        <td class="py-2">{{ $line->quantity_received }}</td>
                        <td class="py-2">{{ $line->quantity_outstanding }}</td>
                        <td class="py-2">{{ ucfirst($line->status) }}</td>
                        <td class="py-2">{{ $line->receipts->pluck('grv_number')->filter()->unique()->implode(', ') ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
