<?php
namespace App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Filament\Resources\ManualStockTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListManualStockTransactions extends ListRecords { protected static string $resource = ManualStockTransactionResource::class; protected function getHeaderActions(): array { return [Actions\CreateAction::make()->label('New Manual / Direct Order')]; } }
