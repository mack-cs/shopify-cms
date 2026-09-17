<?php
namespace App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Filament\Resources\ManualStockTransactionResource;
use Filament\Resources\Pages\CreateRecord;
class CreateManualStockTransaction extends CreateRecord { protected static string $resource = ManualStockTransactionResource::class; protected function getRedirectUrl(): string { return static::getResource()::getUrl('edit', ['record' => $this->record]); } }
