<div class="space-y-4">
    @if($request->status === \App\Models\InventoryAdjustmentRequest::STATUS_CANCELLED)
        @php($cancellation = json_decode((string) $request->shopify_result, true) ?: [])
        <div class="rounded-lg border p-3 text-sm">
            <strong>Cancelled pending inventory pushes</strong>
            <div>{{ $cancellation['reason'] ?? 'See cancellation audit.' }}</div>
            <div>By user {{ $cancellation['cancelled_by'] ?? 'Unknown' }} at {{ $cancellation['cancelled_at'] ?? 'Unknown' }}.</div>
            <div>Shopify inventory was not changed. Audit IDs: {{ implode(', ', $cancellation['cancellation_audit_ids'] ?? []) }}.</div>
        </div>
    @endif
    <div class="text-sm">
        @php($fileReasons = $request->type === 'file' ? $request->items->pluck('reason')->unique()->values() : collect())
        @if($fileReasons->count() === 1)
            <div><strong>Reason for this file:</strong> {{ $fileReasons->first() }}</div>
        @endif
        <div><strong>Requester:</strong> {{ $request->requester?->name ?? 'Unknown' }}</div>
        <div><strong>Approver:</strong> {{ $request->approver?->name ?? 'Unknown' }}</div>
        <div><strong>Submitted:</strong> {{ $request->submitted_at?->format('d/m/Y H:i') ?? 'Unknown' }}</div>
        @if($request->original_filename)
            <div><strong>File:</strong> {{ $request->original_filename }}</div>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2 text-left">SKU/Product</th>
                    <th class="py-2 text-left">Original</th>
                    <th class="py-2 text-left">Requested</th>
                    @if($fileReasons->count() !== 1)<th class="py-2 text-left">Reason</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach($request->items as $item)
                    <tr class="border-b align-top">
                        <td class="py-2">{{ $item->sku }}<br><span class="text-xs text-gray-500">{{ $item->variant?->product?->title }}</span></td>
                        <td class="py-2">{{ $item->original_inventory_tracked ? ($item->original_on_hand_quantity ?? 'Unknown') : 'Not tracked' }}</td>
                        <td class="py-2">{{ $item->requested_inventory_tracked ? ($item->requested_on_hand_quantity ?? 'Unknown') : 'Not tracked' }}</td>
                        @if($fileReasons->count() !== 1)<td class="py-2">{{ $item->reason }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
