<?php

namespace App\Filament\Resources;

use App\Filament\Exports\SiteAuditUrlExporter;
use App\Filament\Resources\SiteAuditUrlResource\Pages;
use App\Jobs\StartSiteAuditRunJob;
use App\Jobs\SyncSiteAuditSitemapJob;
use App\Models\SiteAuditRun;
use App\Models\SiteAuditUrl;
use App\Services\AdminNotification;
use App\Services\SiteAudit\SiteAuditRunnerService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SiteAuditUrlResource extends Resource
{
    protected static ?string $model = SiteAuditUrl::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Site Audit URLs';
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_checked_at', 'desc')
            ->columns([
                TextColumn::make('url')
                    ->label('URL')
                    ->searchable()
                    ->copyable()
                    ->limit(82)
                    ->tooltip(fn (SiteAuditUrl $record): string => $record->url)
                    ->url(fn (SiteAuditUrl $record): string => $record->url)
                    ->openUrlInNewTab(),
                TextColumn::make('resource_type')
                    ->label('Resource')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        SiteAuditUrl::RESOURCE_PRODUCT => 'success',
                        SiteAuditUrl::RESOURCE_COLLECTION => 'info',
                        SiteAuditUrl::RESOURCE_PAGE => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('latestResult.result')
                    ->label('Latest Result')
                    ->badge()
                    ->color(fn (?string $state): string => SiteAuditResultResource::resultColor($state))
                    ->placeholder('Not checked'),
                TextColumn::make('latestResult.status_code')
                    ->label('Status')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('latestResult.response_time_ms')
                    ->label('Load ms')
                    ->badge()
                    ->color(fn (?int $state): string => ((int) ($state ?? 0)) >= SiteAuditResultResource::slowThreshold() ? 'warning' : 'gray')
                    ->placeholder('-'),
                TextColumn::make('latestResult.speed_classification')
                    ->label('Speed')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state ? (SiteAuditResultResource::speedOptions()[$state] ?? 'Unknown') : null)
                    ->color(fn (?string $state): string => SiteAuditResultResource::speedColor($state))
                    ->placeholder('-'),
                TextColumn::make('latestResult.error_reason')
                    ->label('Latest Reason')
                    ->state(fn (SiteAuditUrl $record): ?string => $record->latestResult
                        ? SiteAuditResultResource::reasonSummary($record->latestResult)
                        : null)
                    ->limit(44)
                    ->tooltip(fn (SiteAuditUrl $record): ?string => $record->latestResult
                        ? SiteAuditResultResource::reasonTooltip($record->latestResult)
                        : null)
                    ->extraAttributes([
                        'style' => 'max-width: 16rem; white-space: nowrap;',
                    ])
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_checked_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sitemap_url')
                    ->label('Sitemap')
                    ->copyable()
                    ->limit(64)
                    ->tooltip(fn (SiteAuditUrl $record): ?string => $record->sitemap_url)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestResult.error_message')
                    ->label('Latest Error')
                    ->limit(50)
                    ->tooltip(fn (SiteAuditUrl $record): ?string => $record->latestResult?->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('resource_type')
                    ->label('Resource Type')
                    ->options(SiteAuditResultResource::resourceTypeOptions()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                SelectFilter::make('latest_result')
                    ->label('Latest Result')
                    ->options(SiteAuditResultResource::resultOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'latestResult',
                            fn (Builder $resultQuery): Builder => $resultQuery->where('result', $value),
                        );
                    }),
                SelectFilter::make('latest_speed')
                    ->label('Latest Speed')
                    ->options(SiteAuditResultResource::speedOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'latestResult',
                            fn (Builder $resultQuery): Builder => $resultQuery->where('speed_classification', $value),
                        );
                    }),
            ])
            ->headerActions([
                Action::make('runAuditNow')
                    ->label('Run Audit Now')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        StartSiteAuditRunJob::dispatch(SiteAuditRun::TYPE_MANUAL, Auth::id(), true)
                            ->onQueue((string) config('site-audit.queue', 'default'));

                        self::sendNotification(Notification::make()
                            ->title('Site audit queued')
                            ->body('Sitemap discovery and URL checks will run in the background.')
                            ->success());
                    }),
                Action::make('syncSitemapNow')
                    ->label('Sync Sitemap')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        SyncSiteAuditSitemapJob::dispatch(Auth::id())
                            ->onQueue((string) config('site-audit.queue', 'default'));

                        self::sendNotification(Notification::make()
                            ->title('Sitemap sync queued')
                            ->body('The Shopify sitemap will be synced in the background.')
                            ->success());
                    }),
                ...SiteAuditResultResource::reportLinkActions(),
                ExportAction::make()
                    ->label('Export CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->exporter(SiteAuditUrlExporter::class),
            ])
            ->actions([
                Action::make('checkNow')
                    ->label('Check Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (SiteAuditUrl $record): void {
                        $run = app(SiteAuditRunnerService::class)->runUrl($record, SiteAuditRun::TYPE_MANUAL);

                        self::sendNotification(Notification::make()
                            ->title('URL check queued')
                            ->body("Audit run #{$run->id} queued for {$record->url}.")
                            ->success());
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('checkSelected')
                        ->label('Check Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $ids = $records
                                ->pluck('id')
                                ->map(fn ($id): int => (int) $id)
                                ->values()
                                ->all();

                            if ($ids === []) {
                                self::sendNotification(Notification::make()
                                    ->title('Nothing queued')
                                    ->body('No audit URLs were selected.')
                                    ->warning());

                                return;
                            }

                            $run = app(SiteAuditRunnerService::class)->run(SiteAuditRun::TYPE_MANUAL, $ids);

                            self::sendNotification(Notification::make()
                                ->title('URL checks queued')
                                ->body("Audit run #{$run->id} queued {$run->total_urls} URL check(s).")
                                ->success());
                        }),
                    ExportBulkAction::make()
                        ->exporter(SiteAuditUrlExporter::class),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('latestResult');
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteAuditUrls::route('/'),
        ];
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }
}
