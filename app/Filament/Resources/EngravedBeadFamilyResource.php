<?php

namespace App\Filament\Resources;

use App\Models\EngravedBeadFamily;
use App\Services\EngravedBeads\EngravedBeadFamilyAdministration;
use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
final class EngravedBeadFamilyResource extends Resource
{
    protected static ?string $model = EngravedBeadFamily::class;

    protected static ?string $navigationGroup = 'Shopify Sync';

    protected static ?string $navigationLabel = 'Engraved Bead Families';

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ShopifyPushProducts');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_name')->searchable(),
            TextColumn::make('family_tag')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('configuration_version')->label('Version'),
            TextColumn::make('sync_error')->label('Synchronization failure')->wrap(),
            TextColumn::make('source_variant_id')->label('Tracked source'),
            TextColumn::make('validation_errors')->listWithLineBreaks()->wrap()->label('Validation problems'),
        ])->headerActions([
            Action::make('discover')->label('Reconcile catalog (no Shopify writes)')->action(function (): void {
                app(EngravedBeadFamilyAdministration::class)->authorize(auth()->user());
                app(EngravedBeadFamilyDiscovery::class)->synchronize();
                Notification::make()->title('Catalog reconciliation completed')->success()->send();
            }),
        ])->actions([
            Action::make('inspect')->label('Members, stock and history')
                ->modalContent(fn (EngravedBeadFamily $record) => view('filament.engraved-bead-family', ['family' => $record]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('thresholds')->form(array_map(function (int $q) {
                $fromEnvironment = $q !== 1;

                return TextInput::make('threshold_'.$q)
                    ->label($q.' bead disable threshold')
                    ->numeric()->integer()->minValue($fromEnvironment ? 0 : $q)->maxValue(1000000)
                    ->required(! $fromEnvironment)
                    ->disabled($fromEnvironment)
                    ->dehydrated(! $fromEnvironment)
                    ->helperText($fromEnvironment
                        ? 'Set ENGRAVED_BEAD_'.$q.'_THRESHOLD, refresh cached configuration, and restart queue workers. This does not change beads consumed by a purchase.'
                        : 'The tracked 1-bead product keeps this stored safety buffer.')
                    ->default(fn (EngravedBeadFamily $record) => $fromEnvironment
                        ? app(\App\Services\EngravedBeads\EngravedBeadSafetyThresholds::class)->configured($q)
                        : ($record->members()->where('required_beads', $q)->value('disable_threshold') ?? $q));
            }, [1, 2, 3, 4]))
                ->visible(fn (EngravedBeadFamily $record) => $record->members()->count() === 4)
                ->action(function (EngravedBeadFamily $record, array $data): void {
                    $member = $record->members->firstWhere('required_beads', 1);
                    if ($member !== null) {
                        app(EngravedBeadFamilyAdministration::class)->threshold($member, (int) $data['threshold_1'], auth()->user());
                    }
                    Notification::make()->title('1-bead threshold saved. Multi-bead buffers follow the environment settings.')->success()->send();
                }),
            Action::make('activate')->label('Approve activation')->requiresConfirmation()
                ->visible(fn (EngravedBeadFamily $record) => $record->status === 'pending_validation' && $record->validation_errors === [])
                ->action(function (EngravedBeadFamily $record): void {
                    app(EngravedBeadFamilyAdministration::class)->activate($record, auth()->user());
                    Notification::make()->title('Family activated')->success()->send();
                }),
            Action::make('automaticEnablePermission')->label('Prevent automatic re-enable')
                ->form([
                    Select::make('member')->options(fn (EngravedBeadFamily $record) => $record->members()->pluck('sku', 'id')->all())->required(),
                    Toggle::make('disabled')->label('Keep this product manually disabled')->default(true),
                ])->action(function (EngravedBeadFamily $record, array $data): void {
                    $member = $record->members()->findOrFail($data['member']);
                    app(EngravedBeadFamilyAdministration::class)->manualDisable($member, (bool) $data['disabled'], auth()->user());
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => EngravedBeadFamilyResource\Pages\ListEngravedBeadFamilies::route('/')];
    }
}
