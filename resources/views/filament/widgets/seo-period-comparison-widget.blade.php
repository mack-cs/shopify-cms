<x-filament::widget>
    <x-filament::card>
        @php
            $comparison = $this->latestComparison();
            $current = $comparison['current'] ?? [];
            $previous = $comparison['previous'] ?? null;
            $rows = $this->rows();
        @endphp

        <div class="flex items-center justify-between gap-4">
            <h2 class="text-base font-semibold">Monthly SEO Comparison</h2>
            <span class="text-sm text-gray-500">Search Console performance</span>
        </div>

        @if ($rows === [])
            <div class="mt-4 text-sm text-gray-500">
                No Search Console data imported yet.
            </div>
        @else
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="text-gray-500">Latest Month</div>
                    <div class="font-semibold">{{ $current['label'] ?? '-' }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="text-gray-500">Clicks</div>
                    <div class="font-semibold">{{ $this->formatNumber($current['clicks'] ?? 0) }}</div>
                    @if ($previous)
                        <div class="{{ $this->deltaColor(($current['clicks'] ?? 0) - ($previous['clicks'] ?? 0)) }}">
                            {{ $this->formatDelta(($current['clicks'] ?? 0) - ($previous['clicks'] ?? 0)) }}
                        </div>
                    @endif
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="text-gray-500">Impressions</div>
                    <div class="font-semibold">{{ $this->formatNumber($current['impressions'] ?? 0) }}</div>
                    @if ($previous)
                        <div class="{{ $this->deltaColor(($current['impressions'] ?? 0) - ($previous['impressions'] ?? 0)) }}">
                            {{ $this->formatDelta(($current['impressions'] ?? 0) - ($previous['impressions'] ?? 0)) }}
                        </div>
                    @endif
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="text-gray-500">Avg Position</div>
                    <div class="font-semibold">{{ $this->formatPosition($current['position'] ?? 0) }}</div>
                    @if ($previous)
                        <div class="{{ $this->deltaColor(($current['position'] ?? 0) - ($previous['position'] ?? 0), true) }}">
                            {{ $this->formatDelta(($current['position'] ?? 0) - ($previous['position'] ?? 0), 2, true) }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-5 overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-600">Period</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Clicks</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Clicks +/-</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Clicks %</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Impressions</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Impressions +/-</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Impressions %</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">CTR</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Avg Pos</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Pos +/-</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $row['label'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatNumber($row['clicks']) }}</td>
                                <td class="px-3 py-2 text-right {{ $this->deltaColor($row['clicks_delta']) }}">
                                    {{ $this->formatDelta($row['clicks_delta']) }}
                                </td>
                                <td class="px-3 py-2 text-right {{ $this->deltaColor($row['clicks_percent_delta']) }}">
                                    {{ $this->formatDeltaPercent($row['clicks_percent_delta']) }}
                                </td>
                                <td class="px-3 py-2 text-right">{{ $this->formatNumber($row['impressions']) }}</td>
                                <td class="px-3 py-2 text-right {{ $this->deltaColor($row['impressions_delta']) }}">
                                    {{ $this->formatDelta($row['impressions_delta']) }}
                                </td>
                                <td class="px-3 py-2 text-right {{ $this->deltaColor($row['impressions_percent_delta']) }}">
                                    {{ $this->formatDeltaPercent($row['impressions_percent_delta']) }}
                                </td>
                                <td class="px-3 py-2 text-right">{{ $this->formatPercent($row['ctr']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatPosition($row['position']) }}</td>
                                <td class="px-3 py-2 text-right {{ $this->deltaColor($row['position_delta'], true) }}">
                                    {{ $this->formatDelta($row['position_delta'], 2, true) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::card>
</x-filament::widget>
