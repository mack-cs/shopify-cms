<?php
namespace App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Filament\Resources\ManualStockTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
class ViewManualStockTransaction extends ViewRecord { protected static string $resource = ManualStockTransactionResource::class; protected function getHeaderActions(): array { return [Actions\EditAction::make()->visible(fn () => $this->record->status !== 'completed')]; } }
