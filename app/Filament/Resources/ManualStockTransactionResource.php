<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Jobs\ProcessManualStockTransactionJob;
use App\Models\ManualStockTransaction;
use App\Models\Product;
use App\Services\ManualStockTransactionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ManualStockTransactionResource extends Resource
{
    protected static ?string $model = ManualStockTransaction::class;
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Manual / Direct Orders';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-end-on-rectangle';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Transaction')->schema([
                Forms\Components\Select::make('transaction_type')->options(ManualStockTransaction::TYPES)->required()->default('direct_order'),
                Forms\Components\Select::make('processing_mode')->options([
                    ManualStockTransaction::MODE_PROCESS => 'Deduct inventory in Shopify',
                    ManualStockTransaction::MODE_HISTORICAL => 'Historical record only — do not alter Shopify',
                ])->required()->default(ManualStockTransaction::MODE_PROCESS)->helperText('Historical mode is for stock already deducted outside this CMS.'),
                Forms\Components\TextInput::make('recipient_name')->label('Customer / recipient')->required()->maxLength(255),
                Forms\Components\TextInput::make('reference_number')->label('Invoice / reference')->maxLength(255),
                Forms\Components\DatePicker::make('transaction_date')->required()->default(now()),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make('Products')->schema([
                Forms\Components\Repeater::make('items')->relationship()->schema([
                    Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Product::query()->activeStatus()
                            ->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$search}%")))
                            ->orderBy('title')->limit(50)->pluck('title', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Product::query()->whereKey($value)->value('title')),
                    Forms\Components\TextInput::make('quantity')->numeric()->integer()->minValue(1)->required()->default(1),
                ])->columns(2)->minItems(1)->addActionLabel('Add product'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->orderByDesc('transaction_date')->orderByDesc('created_at'))->columns([
            Tables\Columns\TextColumn::make('transaction_date')->date()->sortable(),
            Tables\Columns\TextColumn::make('reference_number')->searchable()->sortable()->placeholder('—'),
            Tables\Columns\TextColumn::make('recipient_name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('transaction_type')->formatStateUsing(fn ($state) => ManualStockTransaction::TYPES[$state] ?? $state)->badge()->sortable(),
            Tables\Columns\TextColumn::make('items.product_title')->label('Products')->listWithLineBreaks()->searchable(),
            Tables\Columns\TextColumn::make('items.sku')->label('SKUs')->searchable()->listWithLineBreaks()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('status')->badge()->sortable()->color(fn ($state) => match ($state) {'completed' => 'success', 'failed', 'partially_failed', 'needs_attention' => 'danger', 'ready' => 'info', default => 'warning'}),
            Tables\Columns\TextColumn::make('inventory_update_status')->label('Inventory updated')->badge(),
            Tables\Columns\IconColumn::make('is_historical')->label('Historical')->boolean(),
            Tables\Columns\TextColumn::make('creator.name')->label('Created by')->sortable(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('transaction_type')->options(ManualStockTransaction::TYPES),
            Tables\Filters\SelectFilter::make('status')->options(array_combine(['draft','ready','processing','completed','needs_attention','partially_failed','failed'], ['Draft','Ready','Processing','Completed','Needs attention','Partially failed','Failed'])),
            Tables\Filters\SelectFilter::make('processing_mode')->options([ManualStockTransaction::MODE_PROCESS => 'Shopify deduction', ManualStockTransaction::MODE_HISTORICAL => 'Historical']),
            Tables\Filters\Filter::make('transaction_date')->form([
                Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until'),
            ])->query(fn ($query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date))),
        ])->actions([
            Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()->visible(fn ($record) => in_array($record->status, ['draft','ready','needs_attention','failed'], true)),
            Tables\Actions\Action::make('preview')->label('Preview inventory')->icon('heroicon-o-eye')->color('info')
                ->visible(fn ($record) => in_array($record->status, ['draft','ready','needs_attention','failed'], true))->action(function ($record, ManualStockTransactionService $service): void {
                    try { $service->prepare($record); Notification::make()->title('Inventory impact preview refreshed')->success()->send(); }
                    catch (\Throwable $e) { Notification::make()->title('Preview failed')->body($e->getMessage())->danger()->persistent()->send(); }
                }),
            Tables\Actions\Action::make('process')->label(fn ($record) => $record->is_historical ? 'Complete historical record' : 'Confirm & deduct in Shopify')
                ->icon('heroicon-o-check-circle')->color('danger')->requiresConfirmation()
                ->modalDescription(fn ($record) => $record->is_historical ? 'This records the transaction only. Shopify inventory will not change.' : 'This immediately deducts the shown physical component quantities from Shopify On Hand inventory. It does not create a Shopify order.')
                ->visible(fn ($record) => in_array($record->status, ['ready','failed','partially_failed'], true))
                ->action(function ($record): void { ProcessManualStockTransactionJob::dispatch($record->id, Auth::id()); $record->update(['status' => 'queued']); Notification::make()->title('Inventory update queued')->success()->send(); }),
        ])->recordUrl(fn ($record) => self::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Transaction')->schema([
                TextEntry::make('transaction_type')->formatStateUsing(fn ($state) => ManualStockTransaction::TYPES[$state] ?? $state),
                TextEntry::make('recipient_name'), TextEntry::make('reference_number'), TextEntry::make('transaction_date')->date(),
                TextEntry::make('status')->badge(), TextEntry::make('inventory_update_status')->badge(),
                TextEntry::make('is_historical')->label('Historical / backfill')->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')->badge()->color(fn ($state): string => $state ? 'warning' : 'gray'),
                TextEntry::make('creator.name'), TextEntry::make('processor.name'), TextEntry::make('created_at')->dateTime(), TextEntry::make('processed_at')->dateTime(),
                TextEntry::make('notes')->columnSpanFull(), TextEntry::make('last_error')->color('danger')->columnSpanFull(),
            ])->columns(4),
            RepeatableEntry::make('items')->label('Ordered products')->schema([TextEntry::make('product_title'), TextEntry::make('sku'), TextEntry::make('quantity'),
                TextEntry::make('is_stack')->label('Stack product')->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')->badge()->color(fn ($state): string => $state ? 'info' : 'gray'),
                TextEntry::make('processing_result')->columnSpanFull()])->columns(4),
            RepeatableEntry::make('impacts')->label('Shopify inventory impact / component movement ledger')->schema([
                TextEntry::make('product_title'), TextEntry::make('sku'), TextEntry::make('quantity_required')->label('Deduct'),
                TextEntry::make('available_before'), TextEntry::make('on_hand_before'), TextEntry::make('available_after'), TextEntry::make('on_hand_after'),
                TextEntry::make('status')->badge(), TextEntry::make('error_message')->color('danger')->columnSpanFull(),
                TextEntry::make('sources')->label('Ordered product source(s)')->formatStateUsing(fn ($state) => collect($state)->map(fn ($s) => ($s['product'] ?? 'Product').' x '.($s['ordered_quantity'] ?? '?').' -> component '.($s['component_quantity_total'] ?? '?'))->implode("\n"))->columnSpanFull(),
            ])->columns(4),
        ]);
    }

    public static function canDelete($record): bool { return $record->status === 'draft'; }
    public static function getPages(): array { return ['index' => Pages\ListManualStockTransactions::route('/'), 'create' => Pages\CreateManualStockTransaction::route('/create'), 'edit' => Pages\EditManualStockTransaction::route('/{record}/edit'), 'view' => Pages\ViewManualStockTransaction::route('/{record}')]; }
}
