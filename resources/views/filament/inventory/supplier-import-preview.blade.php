<div class="space-y-4 text-sm">
    @php($receiptStates = $batch->type === 'receipt' ? \App\Models\ProcurementSupplierReceipt::with('line')->whereIn('id', collect($batch->preview_rows)->pluck('_saved_id')->filter())->get()->keyBy('id') : collect())
    <p>{{ $batch->valid_count }} valid rows; {{ $batch->invalid_count }} rejected rows.
        @if($batch->status !== 'completed') Nothing has been saved. @endif
        Receipts are not received in Shopify until the separate push is confirmed.</p>
    @if($batch->invalid_count > 0)
        <a class="font-medium underline" href="{{ route('inventory.supplier-imports.rejected', $batch) }}">Download rejected rows for correction</a>
        <p>Rejected rows will not be saved. Correct the downloaded file and upload it again. Existing order quantities will not be overwritten; use Amend Order Line for an authorized change.</p>
    @endif
    @if(($warnings ?? collect())->isNotEmpty())
        <div class="space-y-1"><h3 class="font-semibold">Earlier orders still outstanding</h3>
            @foreach($warnings as $warning)<p>SKU {{ $warning['sku'] }}: order {{ $warning['earlier_order_number'] }} (ETA {{ $warning['earlier_eta'] }}) is still outstanding. This receipt is for {{ $warning['receiving_order_number'] }}.</p>@endforeach
        </div>
    @endif
    @foreach([true => 'Valid rows', false => 'Rejected rows'] as $valid => $label)
        <section class="space-y-2">
            <h3 class="font-semibold">{{ $label }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead><tr><th>Row</th><th>Original values</th><th>Status / reason</th>
                        @if($batch->type === 'receipt')<th>Confirmed outstanding</th><th>Staged</th><th>Needs reconciliation</th><th>Available to receive</th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach(collect($batch->preview_rows)->filter(fn($row) => (bool)($row['_valid'] ?? false) === (bool)$valid) as $row)
                        @php($savedReceipt = $receiptStates->get($row['_saved_id'] ?? null))
                        <tr class="border-t align-top">
                            <td class="p-2">{{ $row['_row'] }}@if(($row['_source_row'] ?? $row['_row']) != $row['_row']) (original {{ $row['_source_row'] }})@endif</td>
                            <td class="p-2">
                                @foreach(($row['_headers'] ?? []) as $index => $header)
                                    @if(!in_array(strtolower(str_replace(' ', '_', $header)), ['error_reason', 'original_row']))
                                        <div><span class="font-medium">{{ $header }}:</span> <span class="whitespace-pre-wrap">{{ $row['_values'][$index] ?? '' }}</span></div>
                                    @endif
                                @endforeach
                                @foreach(array_slice($row['_values'] ?? [], count($row['_headers'] ?? []), preserve_keys: true) as $index => $value)
                                    <div><span class="font-medium">Extra column {{ $index + 1 }}:</span> <span class="whitespace-pre-wrap">{{ $value }}</span></div>
                                @endforeach
                            </td>
                            <td class="p-2">{{ ($row['_saved'] ?? false) ? ($batch->type === 'receipt' ? ($savedReceipt?->receiving_status_label ?? 'Not saved') : 'Saved') : 'Not saved' }}
                                @foreach(($row['_errors'] ?? data_get($batch->errors, (string)$row['_row'], [])) as $error)<div>{{ $error }}</div>@endforeach
                            </td>
                            @if($batch->type === 'receipt')
                                <td>{{ $savedReceipt?->line?->quantity_outstanding ?? $row['_confirmed_outstanding'] ?? '-' }}</td><td>{{ $savedReceipt?->line?->quantity_staged ?? $row['_staged_quantity'] ?? '-' }}</td>
                                <td>{{ $savedReceipt?->line?->quantity_uncertain ?? $row['_uncertain_quantity'] ?? '-' }}</td><td>{{ $savedReceipt?->line?->quantity_available_to_receive ?? $row['_available_to_receive'] ?? '-' }}</td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
