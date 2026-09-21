<?php
namespace App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Filament\Resources\ManualStockTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
class EditManualStockTransaction extends EditRecord { protected static string $resource = ManualStockTransactionResource::class; protected function getHeaderActions(): array { return [Actions\ViewAction::make(), Actions\DeleteAction::make()]; } }
