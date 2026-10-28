<x-filament-panels::page>
    @if (! $launch)
        <p>Choose Launch Products on Shopify from the products list to start a run.</p>
    @else
        <div @if (! $launch->isFinished()) wire:poll.3s="refreshLaunch" @endif class="space-y-4">
            <div class="text-sm text-gray-600">
                Run #{{ $launch->id }} is <strong>{{ str_replace('_', ' ', $launch->status) }}</strong>.
                @if ($launch->user)
                    Started by {{ $launch->user->name }}.
                @endif
                A product is launched only after Shopify confirms both ACTIVE status and every selected sales channel.
            </div>
            <div class="overflow-auto rounded-xl border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th class="p-3">Product</th>
                            <th class="p-3">Validation</th>
                            <th class="p-3">Outcome</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Inventory</th>
                            <th class="p-3">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($launch->items as $item)
                            <tr class="border-t border-gray-200">
                                <td class="p-3">
                                    {{ $item->product_title }}
                                    <div class="text-gray-500">{{ $item->sku }}</div>
                                </td>
                                <td class="p-3">{{ $item->validation }}</td>
                                <td class="p-3">{{ $item->outcome }}</td>
                                <td class="p-3">{{ $item->current_status ?: '—' }} → {{ $item->proposed_status ?: 'active' }}</td>
                                <td class="p-3">
                                    {{ $item->inventory_tracking ?: '—' }}
                                    @if ($item->proposed_inventory !== null)
                                        <div class="text-gray-500">{{ $item->current_inventory ?? '—' }} → {{ $item->proposed_inventory }}</div>
                                    @endif
                                </td>
                                <td class="p-3">{{ $item->detail }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
