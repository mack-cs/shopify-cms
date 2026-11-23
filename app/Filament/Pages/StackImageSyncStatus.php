<?php

namespace App\Filament\Pages;

use App\Enums\RolesEnum;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use App\Services\StackImageSync;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class StackImageSyncStatus extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Products';

    protected static ?string $navigationLabel = 'Stack Image Sync';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string $view = 'filament.pages.stack-image-sync-status';

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active && (Auth::user()?->hasAnyRole([RolesEnum::Admin->value, RolesEnum::SuperAdmin->value]) ?? false);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Product::query()->recognizedStacks()->with('variants'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('handle')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('sync_phase')
                    ->label('Synchronization')
                    ->state(fn (Product $record): string => (string) app(StackImageSync::class)->describe($record)['phase']),
                Tables\Columns\TextColumn::make('source_skus')
                    ->label('Source component SKUs')
                    ->state(fn (Product $record): string => implode(', ', app(StackImageSync::class)->describe($record)['source_skus'])),
                Tables\Columns\TextColumn::make('sync_detail')
                    ->label('Latest outcome')
                    ->state(fn (Product $record): string => (string) (app(StackImageSync::class)->describe($record)['detail'] ?? ''))
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('rebuild')
                    ->label('Rebuild locally')
                    ->action(function (Product $record): void {
                        $result = app(ComponentStackPropagation::class)->stageStack($record);
                        $sync = app(StackImageSync::class);
                        if (($result['rebuilt'] ?? false) === true) {
                            $sync->note($record, null, null, 'local_rebuilt', $result['message'], false);
                            Notification::make()->title('Local gallery rebuilt')->body($result['message'])->success()->send();

                            return;
                        }
                        $sync->note($record, null, null, 'local_rebuild_failed', $result['message'], false);
                        Notification::make()->title('Local gallery was not rebuilt')->body($result['message'])->danger()->send();
                    }),
            ]);
    }
}
