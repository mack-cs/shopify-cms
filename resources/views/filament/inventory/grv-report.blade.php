@php
    $orderedTotal = 0;
    $previouslyReceivedTotal = 0;
    $receivedInGrvTotal = 0;
    $outstandingAfterTotal = 0;

    $rows = $receipts->map(function ($lineReceipt) use (&$orderedTotal, &$previouslyReceivedTotal, &$receivedInGrvTotal, &$outstandingAfterTotal) {
        $line = $lineReceipt->line;
        $receivedAt = $lineReceipt->received_at ?? $lineReceipt->created_at;
        $previouslyReceived = $line
            ? (int) $line->receipts()
                ->where('status', 'succeeded')
                ->where(function ($query) use ($receivedAt, $lineReceipt): void {
                    $query
                        ->where('created_at', '<', $receivedAt)
                        ->orWhere(function ($sameTime) use ($receivedAt, $lineReceipt): void {
                            $sameTime->where('created_at', $receivedAt)->where('id', '<', $lineReceipt->id);
                        });
                })
                ->sum('quantity_received')
            : 0;
        $ordered = (int) ($line?->quantity_ordered ?? 0);
        $receivedInGrv = (int) $lineReceipt->quantity_received;
        $outstandingAfter = max(0, $ordered - $previouslyReceived - $receivedInGrv);

        $orderedTotal += $ordered;
        $previouslyReceivedTotal += $previouslyReceived;
        $receivedInGrvTotal += $receivedInGrv;
        $outstandingAfterTotal += $outstandingAfter;

        return [
            'receipt' => $lineReceipt,
            'ordered' => $ordered,
            'previously_received' => $previouslyReceived,
            'received_in_grv' => $receivedInGrv,
            'outstanding_after' => $outstandingAfter,
        ];
    });

    $receiptStatus = $outstandingAfterTotal > 0 ? 'Partial Receipt' : 'Complete Receipt';
@endphp

<div class="space-y-4 text-sm">
    <div>
        <div class="font-semibold">{{ $receipt->grv_number ?: 'GRV pending' }}</div>
        <div class="mt-1 grid gap-1 text-gray-600 dark:text-gray-300 md:grid-cols-2">
            <div>Order ID: {{ $receipts->pluck('line.order.order_number')->filter()->unique()->implode(', ') ?: '-' }}</div>
            <div>Receipt date/time: {{ ($receipt->received_at ?? $receipt->created_at)?->format('d/m/Y H:i') ?? '-' }}</div>
            <div>Received by: {{ $receipt->createdBy?->name ?? $receipt->createdBy?->email ?? 'Unknown' }}</div>
            <div>GRV Number: {{ $receipt->grv_number ?: '-' }}</div>
            <div>Order quantity before this receipt: {{ $orderedTotal }}</div>
            <div>Quantity already received before this GRV: {{ $previouslyReceivedTotal }}</div>
            <div>Quantity received in this GRV: {{ $receivedInGrvTotal }}</div>
            <div>Outstanding quantity after this GRV: {{ $outstandingAfterTotal }}</div>
            <div>Receipt status: <span class="font-medium">{{ $receiptStatus }}</span></div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2 text-left">SKU</th>
                    <th class="py-2 text-left">Product</th>
                    <th class="py-2 text-left">Ordered Quantity</th>
                    <th class="py-2 text-left">Previously Received</th>
                    <th class="py-2 text-left">Quantity Received in this GRV</th>
                    <th class="py-2 text-left">Outstanding After Receipt</th>
                    <th class="py-2 text-left">Shopify Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($lineReceipt = $row['receipt'])
                    <tr class="border-b align-top">
                        <td class="py-2">{{ $lineReceipt->line?->sku }}</td>
                        <td class="py-2">{{ $lineReceipt->line?->variant?->product?->title ?? '-' }}</td>
                        <td class="py-2">{{ $row['ordered'] }}</td>
                        <td class="py-2">{{ $row['previously_received'] }}</td>
                        <td class="py-2">{{ $row['received_in_grv'] }}</td>
                        <td class="py-2">{{ $row['outstanding_after'] }}</td>
                        <td class="py-2">{{ str_replace('_', ' ', ucfirst($lineReceipt->status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
