<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Shopify adjustment history is the record of what changed in Shopify. CMS records explain who or what started the change and why. Confirmed links use the same reference this CMS sent to Shopify, such as a stack movement id. A probable link is one close record and is not treated as confirmed. An unmatched Shopify row stays visible. Available and on-hand balances appear only when this CMS read them back from Shopify.
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Filters read records stored in this CMS, including the backfilled audit log. Searching does not call Shopify. Shopify adjustment rows appear here after inventory:sync-shopify-history has stored them.
        </p>

        <div class="flex flex-wrap gap-2 text-sm">
            @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '180' => 'Last 180 days'] as $preset => $label)
                <button type="button" wire:click="applyPreset('{{ $preset }}')" class="rounded-lg border border-gray-300 px-3 py-1 dark:border-gray-600">{{ $label }}</button>
            @endforeach
            <span class="px-2 py-1 text-gray-500">Custom range uses the date fields.</span>
            <button type="button" wire:click="emailDirectCorrectionReport" wire:loading.attr="disabled" wire:target="emailDirectCorrectionReport" class="rounded-lg bg-primary-600 px-3 py-1 font-medium text-white">
                <span wire:loading.remove wire:target="emailDirectCorrectionReport">Email 7-day direct corrections</span>
                <span wire:loading wire:target="emailDirectCorrectionReport">Queuing report…</span>
            </button>
        </div>

        @if ($this->directCorrectionOpen)
            @php($directReport = $this->directCorrectionPreview())
            <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-base font-semibold">Direct Shopify corrections, last 7 days</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $directReport['from']->timezone(config('app.timezone'))->format('M j, Y') }}
                    through
                    {{ $directReport['until']->timezone(config('app.timezone'))->format('M j, Y') }}.
                    These are quantity corrections made in Shopify itself. CMS changes are left out. The email is queued for {{ config('services.shopify.direct_inventory_correction_email') }} and every active user.
                </p>
                @if ($directReport['corrections'] === 0)
                    <p class="text-sm">No direct corrections are stored for these dates yet. The queued report reads any missing days in the background and emails the result.</p>
                @else
                    @foreach ($directReport['groups'] as $group)
                        <div>
                            <h3 class="text-sm font-semibold">{{ $group['user'] }} ({{ count($group['rows']) }})</h3>
                            <table class="mt-2 w-full text-left text-sm">
                                <thead class="text-gray-500">
                                    <tr>
                                        <th class="py-1 pr-3">When</th>
                                        <th class="py-1 pr-3">SKU</th>
                                        <th class="py-1 pr-3">Product</th>
                                        <th class="py-1 pr-3">Change</th>
                                        <th class="py-1">App</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($group['rows'] as $row)
                                        <tr class="border-t border-gray-200 dark:border-gray-700">
                                            <td class="py-1 pr-3">{{ $row['occurred_at'] }}</td>
                                            <td class="py-1 pr-3">{{ $row['sku'] }}</td>
                                            <td class="py-1 pr-3">{{ $row['product'] }}</td>
                                            <td class="py-1 pr-3">{{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }} {{ $row['state'] }}</td>
                                            <td class="py-1">{{ $row['app'] !== '' ? $row['app'] : 'Shopify' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif
            </div>
        @endif

        {{ $this->form }}

        @if ($this->searched)
            @php($report = $this->report())
            @php($summary = $report['summary'])
            <div class="space-y-4">
                <div class="flex flex-wrap gap-2 text-sm">
                    <button type="button" wire:click="setInvestigation('all')" class="rounded-lg border px-3 py-1 {{ ($this->applied['investigation'] ?? 'all') === 'all' ? 'border-primary-600 text-primary-700' : 'border-gray-300 dark:border-gray-600' }}">All changes</button>
                    <button type="button" wire:click="setInvestigation('cms')" class="rounded-lg border px-3 py-1 {{ ($this->applied['investigation'] ?? '') === 'cms' ? 'border-primary-600 text-primary-700' : 'border-gray-300 dark:border-gray-600' }}">CMS changes</button>
                    <button type="button" wire:click="setInvestigation('unexplained')" class="rounded-lg border px-3 py-1 {{ ($this->applied['investigation'] ?? '') === 'unexplained' ? 'border-primary-600 text-primary-700' : 'border-gray-300 dark:border-gray-600' }}">Unexplained changes</button>
                    <button type="button" wire:click="export" class="rounded-lg border border-gray-300 px-3 py-1 dark:border-gray-600">Export CSV</button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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

                @if ($report['shopify_message'])
                    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <p>{{ $report['shopify_message'] }}</p>
                        @if ($report['shopify_status'] === 'unavailable')
                            <button type="button" wire:click="recheckShopify" class="mt-3 text-sm font-medium text-primary-600">
                                Check Shopify access again
                            </button>
                        @endif
                    </div>
                @endif

                @if ($report['truncated'])
                    <p class="text-sm text-amber-700 dark:text-amber-300">
                        This result is incomplete. The message above says which page limit was reached. Narrow the dates or filters before treating the list as the full history.
                    </p>
                @endif

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="px-3 py-2">Date</th>
                                <th class="px-3 py-2">Product</th>
                                <th class="px-3 py-2">Activity</th>
                                <th class="px-3 py-2">Change</th>
                                <th class="px-3 py-2">Source</th>
                                <th class="px-3 py-2">Triggered by</th>
                                <th class="px-3 py-2">Match</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['page_rows'] as $row)
                                <tr class="border-t border-gray-200 align-top dark:border-gray-700">
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $row['occurred_at'] }}</td>
                                    <td class="px-3 py-2">
                                        <div>{{ $row['product'] ?: '—' }}</div>
                                        @if (! empty($row['sku']))
                                            <button type="button" wire:click="openSku('{{ $row['sku'] }}')" class="text-xs font-medium text-primary-600">{{ $row['sku'] }}</button>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <button type="button" wire:click="show('{{ $row['key'] }}')" class="text-left font-medium text-primary-600">
                                            {{ $row['activity'] }}
                                        </button>
                                        <div class="text-xs text-gray-500">{{ $row['status_label'] }}</div>
                                        @if (($row['reported_by'] ?? '') === 'local')
                                            <div class="text-xs text-gray-500">Local record</div>
                                        @elseif (($row['reported_by'] ?? '') === 'both')
                                            <div class="text-xs text-gray-500">Shopify and CMS</div>
                                        @else
                                            <div class="text-xs text-gray-500">Shopify reported</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @php($shopifyStates = array_values(array_filter($row['states'] ?? [], fn (array $state): bool => ($state['origin'] ?? '') === 'shopify')))
                                        @if (count($shopifyStates) > 1)
                                            @foreach ($shopifyStates as $state)
                                                <div>{{ ($state['delta'] > 0 ? '+' : '') }}{{ $state['delta'] }} {{ $state['state'] }}</div>
                                            @endforeach
                                        @else
                                            {{ $row['change'] }}
                                        @endif
                                        @if ($row['available'] !== null)
                                            <div class="text-xs text-gray-500">Available {{ $row['available'] }}</div>
                                        @endif
                                        @if ($row['on_hand'] !== null)
                                            <div class="text-xs text-gray-500">On hand {{ $row['on_hand'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $row['source_label'] }}
                                        @if (! empty($row['app']))
                                            <div class="text-xs text-gray-500">{{ $row['app'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $row['triggered_by'] }}
                                        @if (! empty($row['process']) && ! str_contains((string) $row['triggered_by'], (string) $row['process']))
                                            <div class="text-xs text-gray-500">{{ $row['process'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $row['confidence_label'] }}
                                        @foreach ($row['flag_labels'] ?? [] as $flag)
                                            <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $flag }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-6 text-gray-500">No inventory records matched this search.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between text-sm">
                    <span>Page {{ $this->pageNumber }} of {{ $report['page_count'] }} · {{ $summary['operations'] }} operations</span>
                    <span class="space-x-3">
                        <button type="button" wire:click="previousPage" @disabled($this->pageNumber <= 1) class="font-medium text-primary-600 disabled:text-gray-400">Previous</button>
                        <button type="button" wire:click="nextPage" @disabled($this->pageNumber >= $report['page_count']) class="font-medium text-primary-600 disabled:text-gray-400">Next</button>
                    </span>
                </div>

                @if ($report['selected'])
                    @php($selected = $report['selected'])
                    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <h2 class="text-base font-semibold">{{ $selected['activity'] }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $selected['confidence_label'] }} · {{ $selected['status_label'] }} · {{ $selected['change'] }}</p>
                        @if (($selected['flag_labels'] ?? []) !== [])
                            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-amber-800 dark:text-amber-200">
                                @foreach ($selected['flag_labels'] as $flag)
                                    <li>{{ $flag }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <dl class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ($selected['detail'] as $line)
                                <div>
                                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $line[0] }}</dt>
                                    <dd class="text-sm">{{ $line[1] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if (($selected['states'] ?? []) !== [])
                            <h3 class="mt-4 text-sm font-semibold">State changes</h3>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($selected['states'] as $state)
                                    <li>{{ ($state['delta'] > 0 ? '+' : '') }}{{ $state['delta'] }} {{ $state['state'] }} <span class="text-gray-500">({{ $state['origin'] === 'shopify' ? 'Shopify' : 'CMS' }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="mt-4 flex flex-wrap gap-3 text-sm">
                            @foreach ($selected['links'] as $link)
                                <a href="{{ $link[1] }}" class="font-medium text-primary-600">{{ $link[0] }}</a>
                            @endforeach
                            @if (! empty($selected['sku']))
                                <button type="button" wire:click="openSku('{{ $selected['sku'] }}')" class="font-medium text-primary-600">Open this product history</button>
                            @endif
                            @if (($selected['identifiers'] ?? []) !== [])
                                <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js(implode("\n", $selected['identifiers'])))" class="font-medium text-primary-600">Copy identifiers</button>
                            @endif
                        </div>

                        <form wire:submit="saveReview" class="mt-6 space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <h3 class="text-sm font-semibold">Investigation status</h3>
                            <p class="text-xs text-gray-500">This status is stored in the CMS only. It does not change Shopify inventory.</p>
                            <select wire:model="reviewStatus" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                                <option value="needs_investigation">Needs investigation</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="resolved">Resolved</option>
                            </select>
                            <textarea wire:model="reviewNote" rows="2" placeholder="Optional note" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900"></textarea>
                            @if (! empty($selected['reviewed_at']))
                                <p class="text-xs text-gray-500">Last saved {{ $selected['reviewed_at'] }}@if (! empty($selected['reviewed_by'])) by {{ $selected['reviewed_by'] }}@endif.</p>
                            @endif
                            <button type="submit" class="rounded-lg bg-primary-600 px-3 py-1 text-sm font-medium text-white">Save status</button>
                        </form>
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
