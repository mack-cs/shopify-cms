<x-filament::widget>
    <x-filament::card>
        @php($context = $this->context())

        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-primary-600">Organic search performance</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                    {{ $context['current_label'] }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $context['current_dates'] }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3 lg:min-w-[640px]">
                <div class="border-l-2 border-primary-500 pl-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Compared with</div>
                    <div class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $context['previous_label'] ?? 'No earlier period' }}
                    </div>
                    <div class="text-xs text-gray-500">{{ $context['previous_dates'] ?? '—' }}</div>
                    @if (($context['comparison_is_fair'] ?? null) === false)
                        <div class="mt-1 text-xs font-medium text-amber-600">Different period lengths</div>
                    @elseif (($context['comparison_is_fair'] ?? null) === true)
                        <div class="mt-1 text-xs font-medium text-green-600">Matched period length</div>
                    @endif
                </div>
                <div class="border-l-2 border-gray-300 pl-3 dark:border-gray-700">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Metric scope</div>
                    <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $this->scopeLabel() }}</div>
                    <div class="text-xs text-gray-500">Change using dashboard filters</div>
                </div>
                <div class="border-l-2 border-gray-300 pl-3 dark:border-gray-700">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Last updated</div>
                    <div class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $context['last_updated']?->timezone(config('app.timezone'))->format('j M Y, H:i') ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-gray-500">Imported Search Console data</div>
                </div>
            </div>
        </div>
    </x-filament::card>
</x-filament::widget>
