<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            This list includes CMS inventory records and Shopify adjustments stored in this CMS, including corrections made directly in Shopify. Use the table search for a product name or SKU. Other filters are in the filter menu. This page does not call Shopify.
        </p>

        @if ($notice = $this->shopifyNotice())
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $notice }}</p>
        @endif

        @php($summary = $this->auditSummary())
        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.75rem;">
            @foreach ([
                'Operations' => $summary['operations'],
                'CMS-originated' => $summary['cms_operations'],
                'Shopify-originated' => $summary['shopify_operations'],
                'Unexplained' => $summary['unexplained'],
                'Products' => $summary['products'],
                'State lines increased' => $summary['positive_lines'],
                'State lines decreased' => $summary['negative_lines'],
            ] as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="text-lg font-semibold">{{ $value }}</div>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">Increased and decreased counts are separate state lines. They are not added together into a stock total.</p>

        {{ $this->table }}

        @if ($this->directCorrectionOpen)
            @php($directReport = $this->directCorrectionPreview())
            <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-base font-semibold">Direct Shopify corrections, last 7 days</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $directReport['from']->timezone(config('app.timezone'))->format('M j, Y') }}
                    through
                    {{ $directReport['until']->timezone(config('app.timezone'))->format('M j, Y') }}.
                    The email is queued for {{ config('services.shopify.direct_inventory_correction_email') }}.
                </p>
                @if ($directReport['corrections'] === 0)
                    <p class="text-sm">No direct corrections are stored for these dates yet. The queued report reads any missing days in the background and emails the result.</p>
                @else
                    @foreach ($directReport['groups'] as $group)
                        <div>
                            <h3 class="text-sm font-semibold">{{ $group['user'] }} ({{ count($group['rows']) }})</h3>
                            <ul class="mt-1 text-sm">
                                @foreach ($group['rows'] as $row)
                                    <li>{{ $row['occurred_at'] }} · {{ $row['sku'] }} · {{ $row['product'] }} · {{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }} {{ $row['state'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
