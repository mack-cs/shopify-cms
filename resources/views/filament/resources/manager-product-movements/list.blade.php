<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        <x-filament-panels::resources.tabs />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->getManagerStats() as $stat)
                <x-filament::section compact>
                    <div class="space-y-1">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            {{ $stat['label'] }}
                        </div>

                        <div @class([
                            'text-3xl font-semibold tracking-tight',
                            'text-gray-950 dark:text-white' => $stat['color'] === 'gray',
                            'text-success-600 dark:text-success-400' => $stat['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $stat['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $stat['color'] === 'danger',
                        ])>
                            {{ $stat['value'] }}
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(
            \Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
            scopes: $this->getRenderHookScopes(),
        ) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(
            \Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER,
            scopes: $this->getRenderHookScopes(),
        ) }}
    </div>
</x-filament-panels::page>
