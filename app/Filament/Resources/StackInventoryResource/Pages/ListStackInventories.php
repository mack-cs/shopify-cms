<?php

namespace App\Filament\Resources\StackInventoryResource\Pages;

use App\Filament\Resources\StackInventoryResource;
use App\Jobs\CheckShopifyStackInventory;
use App\Services\StackInventoryAuditService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListStackInventories extends ListRecords
{
    protected static string $resource = StackInventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkShopifyStock')
                ->label('Check Shopify Stock')
                ->icon('heroicon-o-arrow-path')
                ->disabled(fn (): bool => Cache::has(CheckShopifyStackInventory::PENDING_KEY))
                ->action(function (): void {
                    $this->authorizeAccess();
                    if (!Cache::add(CheckShopifyStackInventory::PENDING_KEY, true, now()->addHour())) {
                        return;
                    }

                    Cache::forget(CheckShopifyStackInventory::ERROR_KEY);
                    try {
                        CheckShopifyStackInventory::dispatch();
                    } catch (\Throwable $exception) {
                        Cache::forget(CheckShopifyStackInventory::PENDING_KEY);
                        throw $exception;
                    }

                    Notification::make()->title('Shopify stock check queued')->success()->send();
                }),
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $this->authorizeAccess();
        $query = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($query): void {
            $csv = Writer::createFromPath('php://output', 'w');
            $csv->insertOne([
                'Stack Name', 'Stack SKU', 'Stack Status', 'Stack Status Reason',
                'Component', 'Component Name', 'Component SKU', 'Quantity Per Stack',
                'Available', 'On Hand', 'Component Status', 'Component Status Reason', 'Last Synced At',
            ]);

            foreach ($query->lazy(200) as $stack) {
                $audit = new StackInventoryAuditService();
                $health = $audit->health($stack);
                $base = [$stack->title, $stack->sku, $health['status'], $health['reason']];
                $position = 1;
                while (($component = $audit->component($stack, $position)) !== null) {
                    $csv->insertOne(array_merge($base, [
                        $position, $component['title'], $component['sku'], $component['quantity_per_stack'],
                        $component['tracked'] === false ? 'Not tracked' : ($component['available'] ?? 'Unknown'),
                        $component['tracked'] === false ? 'Not tracked' : ($component['on_hand'] ?? 'Unknown'),
                        $component['status'], $component['reason'], $component['synced_at']?->toIso8601String(),
                    ]));
                    $position++;
                }
                if ($position === 1) {
                    $csv->insertOne(array_merge($base, array_fill(0, 9, '')));
                }
            }
        }, 'stack-inventory-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function getSubheading(): ?string
    {
        $checkedAt = StackInventoryResource::shopifyReport()['checked_at'] ?? null;
        $result = $checkedAt
            ? 'Latest Shopify check: '.\Illuminate\Support\Carbon::parse($checkedAt)->timezone(config('app.timezone'))->format('d/m/Y H:i').'.'
            : 'Component quantities are from the latest Shopify inventory sync.';

        return match (true) {
            Cache::has(CheckShopifyStackInventory::PENDING_KEY) => 'Checking Shopify in the background. '.$result,
            Cache::has(CheckShopifyStackInventory::ERROR_KEY) => 'The Shopify stock check failed. '.$result,
            default => $result,
        };
    }
}
