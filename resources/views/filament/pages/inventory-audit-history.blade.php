<x-filament-panels::page>
    <div class="space-y-4">
        <div style="display:flex;gap:0.5rem;">
            <button type="button" wire:click="setAuditTab('adjustments')" style="border-radius:0.5rem;padding:0.4rem 0.8rem;font-size:0.875rem;{{ $this->auditTab === 'adjustments' ? 'background:#111827;color:#fff;' : 'background:#fff;color:#111827;border:1px solid #d1d5db;' }}">
                Inventory adjustments
            </button>
            <button type="button" wire:click="setAuditTab('operations')" style="border-radius:0.5rem;padding:0.4rem 0.8rem;font-size:0.875rem;{{ $this->auditTab === 'operations' ? 'background:#111827;color:#fff;' : 'background:#fff;color:#111827;border:1px solid #d1d5db;' }}">
                Inventory operations
            </button>
        </div>

        <p class="text-sm text-gray-600 dark:text-gray-300">
            @if ($this->auditTab === 'adjustments')
                Inventory adjustments are quantity corrections and manual edits from Shopify or the CMS. One save that changes available and on hand is one adjustment. Supplier receipts, stack reservations, and sales are on Inventory operations. Searching a product includes CMS quantity edits from the last three years.
            @else
                Inventory operations are stack reservations, reserved stock consumption, supplier receipts, and other automated workflows, with the existing match and review detail.
            @endif
            The date filter starts at the last 120 days. This page does not call Shopify or change stock.
        </p>

        <p class="text-sm text-gray-600 dark:text-gray-300">Showing {{ $this->appliedDateRange() }}.</p>

        @if ($notice = $this->shopifyNotice())
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $notice }}</p>
        @endif

        @php($summary = $this->auditSummary())
        <div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:0.75rem;">
            @foreach ($this->auditTab === 'adjustments' ? [
                'Adjustments' => $summary['adjustments'],
                'Increases' => $summary['increases'],
                'Decreases' => $summary['decreases'],
                'CMS-originated' => $summary['cms_originated'],
                'Shopify-originated' => $summary['shopify_originated'],
                'Unexplained' => $summary['unexplained'],
            ] : [
                'Operations' => $summary['operations'],
                'CMS-originated' => $summary['cms_operations'],
                'Shopify-originated' => $summary['shopify_operations'],
                'Unexplained' => $summary['unexplained'],
                'Increased lines' => $summary['positive_lines'],
                'Decreased lines' => $summary['negative_lines'],
            ] as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="text-lg font-semibold">{{ $value }}</div>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">
            @if ($this->auditTab === 'adjustments')
                Each count is one logical adjustment. Increases and decreases are not added together into a stock total.
            @else
                Increased and decreased counts are separate state lines. They are not added together into a stock total.
            @endif
        </p>

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
