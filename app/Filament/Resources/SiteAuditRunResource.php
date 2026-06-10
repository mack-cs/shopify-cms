<?php

namespace App\Filament\Resources;

use App\Filament\Exports\SiteAuditRunExporter;
use App\Filament\Resources\SiteAuditRunResource\Pages;
use App\Jobs\StartSiteAuditRunJob;
use App\Jobs\SyncSiteAuditSitemapJob;
use App\Models\SiteAuditRun;
use App\Services\AdminNotification;
use App\Services\SiteAudit\SiteAuditRunnerService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SiteAuditRunResource extends Resource
{
    protected static ?string $model = SiteAuditRun::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Site Audit Runs';
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Run')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => $state === SiteAuditRun::TYPE_MANUAL ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SiteAuditRun::STATUS_COMPLETED => 'success',
                        SiteAuditRun::STATUS_FAILED => 'danger',
                        SiteAuditRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('total_urls')
                    ->label('Total')
                    ->sortable(),
                TextColumn::make('checked_urls')
                    ->label('Checked')
                    ->sortable(),
                TextColumn::make('failed_urls')
                    ->label('Issues')
                    ->badge()
                    ->color(fn (?int $state): string => ((int) ($state ?? 0)) > 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (SiteAuditRun $record): string => "{$record->checked_urls}/{$record->total_urls}"),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(80)
                    ->tooltip(fn (SiteAuditRun $record): ?string => $record->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        SiteAuditRun::TYPE_SCHEDULED => 'Scheduled',
                        SiteAuditRun::TYPE_MANUAL => 'Manual',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        SiteAuditRun::STATUS_PENDING => 'Pending',
                        SiteAuditRun::STATUS_RUNNING => 'Running',
                        SiteAuditRun::STATUS_COMPLETED => 'Completed',
                        SiteAuditRun::STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->headerActions([
                Action::make('runAuditNow')
                    ->label('Run Audit Now')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Run site audit now?')
                    ->modalDescription('This queues sitemap discovery and then queues URL checks for every active sitemap URL.')
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
                Action::make('finalizeRuns')
                    ->label('Finalize Runs')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->action(function (): void {
                        $finalized = app(SiteAuditRunnerService::class)->finalizeRunningRuns();

                        self::sendNotification(Notification::make()
                            ->title('Finalize complete')
                            ->body("Finalized {$finalized} running audit run(s).")
                            ->success());
                    }),
                ...SiteAuditResultResource::reportLinkActions(),
                ExportAction::make()
                    ->label('Export CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->exporter(SiteAuditRunExporter::class),
            ])
            ->actions([
                Action::make('viewResults')
                    ->label('Results')
                    ->icon('heroicon-o-document-chart-bar')
                    ->url(fn (SiteAuditRun $record): string => SiteAuditResultResource::getUrl('index', ['run' => $record->id])),
            ])
            ->bulkActions([
                ExportBulkAction::make()
                    ->exporter(SiteAuditRunExporter::class),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
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
            'index' => Pages\ListSiteAuditRuns::route('/'),
        ];
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }
}
