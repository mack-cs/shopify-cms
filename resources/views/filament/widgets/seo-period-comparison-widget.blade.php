<x-filament::widget>
    <div class="space-y-6">
        @php
            $comparison = $this->latestComparison();
            $rows = $this->rows();
            $topPages = $this->topPages();
            $topQueries = $this->topQueries();
            $movers = $this->movers();
            $opportunities = $this->opportunities();
        @endphp

        <x-filament::card>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-primary-600">Executive summary</p>
                    <h2 class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">What changed and where to focus</h2>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $comparison['current']['label'] ?? 'No period' }}
                </span>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($this->highlights() as $highlight)
                    <div class="flex gap-3 rounded-xl bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-primary-500"></span>
                        <span>{{ $highlight }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-filament::card>
                <h3 class="font-semibold text-gray-950 dark:text-white">Top pages</h3>
                <p class="mt-1 text-xs text-gray-500">Pages generating the most organic clicks</p>
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($topPages as $row)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $row['entity'] }}">{{ $row['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $this->formatNumber($row['impressions']) }} impressions · Pos {{ $this->formatPosition($row['position']) }}</div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $this->formatNumber($row['clicks']) }}</div>
                                <div class="text-xs text-gray-500">clicks</div>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">No page-level data for this period.</p>
                    @endforelse
                </div>
            </x-filament::card>

            <x-filament::card>
                <h3 class="font-semibold text-gray-950 dark:text-white">Top search queries</h3>
                <p class="mt-1 text-xs text-gray-500">Queries driving current organic traffic</p>
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($topQueries as $row)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $this->formatPercent($row['ctr']) }} CTR · Pos {{ $this->formatPosition($row['position']) }}</div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $this->formatNumber($row['clicks']) }}</div>
                                <div class="text-xs text-gray-500">clicks</div>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">No query-level data for this period.</p>
                    @endforelse
                </div>
            </x-filament::card>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-filament::card>
                <div class="flex items-center gap-2">
                    <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-950 dark:text-green-300">Growing</span>
                    <h3 class="font-semibold text-gray-950 dark:text-white">Top page winners</h3>
                </div>
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($movers['winners'] as $row)
                        <div class="flex items-center justify-between gap-4 py-3 text-sm">
                            <span class="truncate text-gray-800 dark:text-gray-200" title="{{ $row['entity'] }}">{{ $row['label'] }}</span>
                            <span class="shrink-0 font-semibold text-green-600">+{{ $this->formatNumber($row['clicks_delta']) }} clicks</span>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">No earlier page data to calculate winners.</p>
                    @endforelse
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="flex items-center gap-2">
                    <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">Declining</span>
                    <h3 class="font-semibold text-gray-950 dark:text-white">Biggest page declines</h3>
                </div>
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($movers['losers'] as $row)
                        <div class="flex items-center justify-between gap-4 py-3 text-sm">
                            <span class="truncate text-gray-800 dark:text-gray-200" title="{{ $row['entity'] }}">{{ $row['label'] }}</span>
                            <span class="shrink-0 font-semibold text-red-600">{{ $this->formatNumber($row['clicks_delta']) }} clicks</span>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">No page declines found for this comparison.</p>
                    @endforelse
                </div>
            </x-filament::card>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-filament::card>
                <h3 class="font-semibold text-gray-950 dark:text-white">Page-one opportunities</h3>
                <p class="mt-1 text-xs text-gray-500">High-impression pages ranking in positions 8–20</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="pb-2 text-left font-medium">Page</th>
                                <th class="pb-2 text-right font-medium">Position</th>
                                <th class="pb-2 text-right font-medium">Impressions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($opportunities['ranking'] as $row)
                                <tr>
                                    <td class="max-w-xs truncate py-3 pr-4 font-medium" title="{{ $row['entity'] }}">{{ $row['label'] }}</td>
                                    <td class="py-3 text-right">{{ $this->formatPosition($row['position']) }}</td>
                                    <td class="py-3 text-right">{{ $this->formatNumber($row['impressions']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-center text-gray-500">No matching opportunities.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::card>

            <x-filament::card>
                <h3 class="font-semibold text-gray-950 dark:text-white">CTR opportunities</h3>
                <p class="mt-1 text-xs text-gray-500">High-impression queries receiving relatively few clicks</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="pb-2 text-left font-medium">Query</th>
                                <th class="pb-2 text-right font-medium">CTR</th>
                                <th class="pb-2 text-right font-medium">Impressions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($opportunities['ctr'] as $row)
                                <tr>
                                    <td class="max-w-xs truncate py-3 pr-4 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-3 text-right">{{ $this->formatPercent($row['ctr']) }}</td>
                                    <td class="py-3 text-right">{{ $this->formatNumber($row['impressions']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-center text-gray-500">No matching opportunities.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::card>
        </div>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-950 dark:text-white">Monthly history</h3>
                    <p class="mt-1 text-xs text-gray-500">Compact view of the latest eight reporting periods</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <tr>
                            <th class="px-3 py-3 text-left font-medium">Period</th>
                            <th class="px-3 py-3 text-right font-medium">Clicks</th>
                            <th class="px-3 py-3 text-right font-medium">Change</th>
                            <th class="px-3 py-3 text-right font-medium">Impressions</th>
                            <th class="px-3 py-3 text-right font-medium">CTR</th>
                            <th class="px-3 py-3 text-right font-medium">Avg position</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-3 font-medium">{{ $row['label'] }}</td>
                                <td class="px-3 py-3 text-right">{{ $this->formatNumber($row['clicks']) }}</td>
                                <td class="px-3 py-3 text-right {{ $this->deltaColor($row['clicks_percent_delta']) }}">
                                    {{ $this->formatDeltaPercent($row['clicks_percent_delta']) }}
                                </td>
                                <td class="px-3 py-3 text-right">{{ $this->formatNumber($row['impressions']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $this->formatPercent($row['ctr']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $this->formatPosition($row['position']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500">No Search Console data imported yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::card>
    </div>
</x-filament::widget>
