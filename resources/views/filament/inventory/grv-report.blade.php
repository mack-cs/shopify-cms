@php
    $orderedTotal=0; $previouslyReceivedTotal=0; $receivedInGrvTotal=0; $outstandingAfterTotal=0; $rows=collect();
    foreach($receipts->groupBy('supplier_order_line_id') as $group) {
        $line=$group->first()->line; $first=$group->sortBy('id')->first();
        $previous=(int)($line?->receipts()->where('status','succeeded')->whereNotIn('id',$group->pluck('id'))
            ->where(function($query) use($first) { $query->where('created_at','<',$first->created_at)
                ->orWhere(fn($same)=>$same->where('created_at',$first->created_at)->where('id','<',$first->id)); })->sum('quantity_received')??0);
        $confirmed=(int)$group->where('status','succeeded')->sum('quantity_received');
        $ordered=(int)($line?->quantity_ordered??0); $outstanding=max(0,$ordered-$previous-$confirmed);
        $orderedTotal+=$ordered; $previouslyReceivedTotal+=$previous; $receivedInGrvTotal+=$confirmed; $outstandingAfterTotal+=$outstanding;
        foreach($group as $lineReceipt) $rows->push(['receipt'=>$lineReceipt,'ordered'=>$ordered,'previously_received'=>$previous,
            'received_in_grv'=>$lineReceipt->status==='succeeded'?$lineReceipt->quantity_received:0,'outstanding_after'=>$outstanding]);
    }
    $stagedTotal=$receipts->whereIn('status',['pending','processing'])->sum('quantity_received');
    $uncertainTotal=$receipts->where('status','manual_review')->sum('quantity_received');
    $receiptStatus=$receipts->contains('status','manual_review')?'Needs reconciliation':($receipts->contains('status','processing')?'Sending to Shopify':
        ($receipts->contains('status','pending')?'Waiting to be pushed':($receipts->contains('post_process_status','failed')?'Received in Shopify; stock summary needs updating':'Received in Shopify')));
@endphp

<div class="space-y-4 text-sm">
    <div>
        <div class="font-semibold">{{ $receipt->grv_number ?: 'GRV pending' }}</div>
        <div class="mt-1 grid gap-1 text-gray-600 dark:text-gray-300 md:grid-cols-2">
            <div>Order ID: {{ $receipts->pluck('line.order.order_number')->filter()->unique()->implode(', ') ?: '-' }}</div>
            <div>Recorded date/time: {{ ($receipt->received_at ?? $receipt->created_at)?->format('d/m/Y H:i') ?? '-' }}</div>
            <div>Recorded by: {{ $receipt->createdBy?->name ?? $receipt->createdBy?->email ?? 'Unknown' }}</div>
            <div>GRV Number: {{ $receipt->grv_number ?: '-' }}</div>
            <div>Order quantity before this receipt: {{ $orderedTotal }}</div>
            <div>Quantity already received before this GRV: {{ $previouslyReceivedTotal }}</div>
            <div>Quantity received in this GRV (confirmed in Shopify): {{ $receivedInGrvTotal }}</div>
            <div>Outstanding quantity after this GRV: {{ $outstandingAfterTotal }}</div>
            <div>Staged quantity: {{ $stagedTotal }}</div><div>Quantity needing reconciliation: {{ $uncertainTotal }}</div><div>Receipt status: <span class="font-medium">{{ $receiptStatus }}</span></div>
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
                        <td class="py-2">{{ $lineReceipt->receiving_status_label }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
