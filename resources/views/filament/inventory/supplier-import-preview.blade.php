@if(! $batch)
    <p class="text-sm">The review is no longer available. Paste or upload the rows again.</p>
@else
@php
    $rows = collect($batch?->preview_rows ?? []);
    $valid = $rows->filter(fn ($row) => (bool) ($row['_valid'] ?? false));
    $rejected = $rows->reject(fn ($row) => (bool) ($row['_valid'] ?? false));
    $isOrder = ($batch->type ?? 'order') === 'order';
    $quantity = fn (array $row): string => (string) ($row['quantity_ordered'] ?? $row['quantity_received'] ?? '');
    $reason = fn (array $row): string => implode(' ', array_unique((array) ($row['_errors'] ?? data_get($batch->errors ?? [], (string) ($row['_row'] ?? ''), []))));
    $extra = function (array $row) use ($quantity): string {
        $shown = array_map(fn ($value) => trim((string) $value), array_filter([
            $row['sku'] ?? null,
            $row['order_id'] ?? null,
            $quantity($row),
            $row['eta'] ?? null,
        ], fn ($value) => trim((string) $value) !== ''));

        return collect($row['_values'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '' && ! in_array($value, $shown, true))
            ->implode(' | ');
    };
@endphp
<div class="space-y-3 text-sm">
    <p>{{ $rows->count() }} submitted, {{ $batch->valid_count ?? 0 }} valid, {{ $batch->invalid_count ?? 0 }} rejected. {{ $batch?->created_at?->format('d/m/Y H:i') }} · {{ $isOrder ? 'Purchase order' : 'Receipt' }}.</p>
    @if(($batch->invalid_count ?? 0) > 0)
        <p><a class="font-medium underline" href="{{ route('inventory.supplier-imports.rejected', $batch) }}">Download rejected rows</a></p>
    @endif
    @if(($batch->valid_count ?? 0) < 1)
        <p>No valid rows can be saved.</p>
    @endif
    @if($valid->isNotEmpty())
        <div>
            <p class="font-medium">Valid rows</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr>
                            <th class="py-1 pr-3">Row</th>
                            <th class="py-1 pr-3">SKU</th>
                            <th class="py-1 pr-3">Order ID</th>
                            <th class="py-1 pr-3">Quantity</th>
                            @if($isOrder)<th class="py-1 pr-3">ETA</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($valid as $row)
                            <tr class="border-t">
                                <td class="py-1 pr-3">{{ $row['_source_row'] ?? $row['_row'] }}</td>
                                <td class="py-1 pr-3">{{ $row['sku'] ?? '' }}</td>
                                <td class="py-1 pr-3">{{ $row['order_id'] ?? '' }}</td>
                                <td class="py-1 pr-3">{{ $quantity($row) }}</td>
                                @if($isOrder)<td class="py-1 pr-3">{{ $row['eta'] ?? '' }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    @if($rejected->isNotEmpty())
        <div>
            <p class="font-medium">Rejected rows</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr>
                            <th class="py-1 pr-3">Row</th>
                            <th class="py-1 pr-3">SKU</th>
                            <th class="py-1 pr-3">Order ID</th>
                            <th class="py-1 pr-3">Quantity</th>
                            @if($isOrder)<th class="py-1 pr-3">ETA</th>@endif
                            <th class="py-1 pr-3">Extra</th>
                            <th class="py-1">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rejected as $row)
                            <tr class="border-t align-top">
                                <td class="py-1 pr-3">{{ $row['_source_row'] ?? $row['_row'] }}</td>
                                <td class="py-1 pr-3">{{ $row['sku'] ?? '' }}</td>
                                <td class="py-1 pr-3">{{ $row['order_id'] ?? '' }}</td>
                                <td class="py-1 pr-3">{{ $quantity($row) }}</td>
                                @if($isOrder)<td class="py-1 pr-3">{{ $row['eta'] ?? '' }}</td>@endif
                                <td class="py-1 pr-3">{{ $extra($row) }}</td>
                                <td class="py-1">{{ $reason($row) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endif
