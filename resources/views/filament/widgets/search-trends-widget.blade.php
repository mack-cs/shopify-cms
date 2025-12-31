<x-filament::widget>
    <x-filament::card>
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold">Search Trends</h2>
            @if ($latestPeriod)
                <span class="text-sm text-gray-500">Period: {{ $latestPeriod }}</span>
            @endif
        </div>

        @if (!$latestPeriod)
            <div class="mt-4 text-sm text-gray-500">
                No trend data yet. Import a CSV to populate this widget.
            </div>
        @else
            <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div>
                    <div class="mb-2 text-sm font-semibold text-gray-700">Top Queries</div>
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Query</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">Clicks</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">CTR %</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">Pos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($queries as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ $row->label }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row->clicks }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row->ctr, 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row->position, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="mb-2 text-sm font-semibold text-gray-700">Top Pages</div>
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Page</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">Clicks</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">CTR %</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600">Pos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pages as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ $row->label }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row->clicks }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row->ctr, 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row->position, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::card>
</x-filament::widget>
