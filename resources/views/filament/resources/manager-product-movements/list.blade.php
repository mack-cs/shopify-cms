<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        <x-filament-panels::resources.tabs />

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
            @foreach ($this->getManagerStats() as $stat)
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                     style="padding: 0.875rem 1rem; min-height: 82px;">
                    <div style="display: flex; height: 100%; flex-direction: column; justify-content: center; gap: 0.2rem;">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            {{ $stat['label'] }}
                        </div>

                        <div @class([
                            'text-2xl font-semibold tracking-tight',
                            'text-gray-950 dark:text-white' => $stat['color'] === 'gray',
                            'text-success-600 dark:text-success-400' => $stat['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $stat['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $stat['color'] === 'danger',
                        ])>
                            {{ $stat['value'] }}
                        </div>
                    </div>
                </div>
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
