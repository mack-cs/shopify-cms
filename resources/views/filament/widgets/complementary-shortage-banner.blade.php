<div>
    @if (($count ?? 0) > 0)
        <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-semibold">Complementary product shortages detected</div>
                    <div class="mt-1">
                        {{ $count }} product{{ $count === 1 ? '' : 's' }} currently {{ $count === 1 ? 'has' : 'have' }} fewer than {{ \App\Services\ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT }} eligible complementary products.
                        These products need more valid backups, otherwise Shopify cannot keep the full complementary set.
                    </div>

                    @if (!empty($items))
                        <div class="mt-3 space-y-1 text-xs">
                            @foreach ($items as $item)
                                <div>
                                    <strong>{{ $item['title'] }}</strong>
                                    @if (!empty($item['handle']))
                                        <span>({{ $item['handle'] }})</span>
                                    @endif
                                    <span> | eligible: {{ $item['local_valid_count'] }}/{{ \App\Services\ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT }}</span>
                                    @if (!empty($item['checked_at']))
                                        <span> | checked {{ $item['checked_at'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <x-filament::button
                    tag="a"
                    href="{{ $auditUrl }}"
                    color="danger"
                    outlined
                >
                    View Audit
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
