<div class="space-y-4 text-sm">
    <div>
        <div class="font-semibold">{{ $receipt->grv_number ?: 'GRV pending' }}</div>
        <div class="mt-1 text-gray-600 dark:text-gray-300">
            Supplier Order:
            {{ $receipts->pluck('line.order.order_number')->filter()->unique()->implode(', ') ?: '-' }}
            - Supplier:
            {{ $receipts->pluck('line.variant.product.vendor')->filter()->unique()->implode(', ') ?: '-' }}
        </div>
        <div class="mt-1 text-gray-600 dark:text-gray-300">
            Received:
            {{ ($receipt->received_at ?? $receipt->created_at)?->format('d/m/Y H:i') ?? '-' }}
            - By {{ $receipt->createdBy?->name ?? 'Unknown' }}
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2 text-left">SKU</th>
                    <th class="py-2 text-left">Product</th>
                    <th class="py-2 text-left">Quantity Received</th>
                    <th class="py-2 text-left">Shopify Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($receipts as $lineReceipt)
                    <tr class="border-b align-top">
                        <td class="py-2">{{ $lineReceipt->line?->sku }}</td>
                        <td class="py-2">{{ $lineReceipt->line?->variant?->product?->title ?? '-' }}</td>
                        <td class="py-2">{{ $lineReceipt->quantity_received }}</td>
                        <td class="py-2">{{ str_replace('_', ' ', ucfirst($lineReceipt->status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
