<?php

namespace App\Filament\Resources;

use App\Filament\Exports\SiteAuditResultExporter;
use App\Filament\Resources\SiteAuditResultResource\Pages;
use App\Models\SiteAuditResult;
use App\Models\SiteAuditRun;
use App\Services\AdminNotification;
use App\Services\SiteAudit\SiteAuditRunnerService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SiteAuditResultResource extends Resource
{
    protected static ?string $model = SiteAuditResult::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Site Audit Results';
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';
    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Checked')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('site_audit_run_id')
                    ->label('Run')
                    ->sortable()
                    ->url(fn (SiteAuditResult $record): string => self::getUrl('index', ['run' => $record->site_audit_run_id])),
                TextColumn::make('siteAuditRun.type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => $state === SiteAuditRun::TYPE_MANUAL ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('siteAuditUrl.url')
                    ->label('URL')
                    ->searchable()
                    ->copyable()
                    ->limit(72)
                    ->tooltip(fn (SiteAuditResult $record): ?string => $record->siteAuditUrl?->url)
                    ->url(fn (SiteAuditResult $record): ?string => $record->siteAuditUrl?->url)
                    ->openUrlInNewTab(),
                TextColumn::make('siteAuditUrl.resource_type')
                    ->label('Resource')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'product' => 'success',
                        'collection' => 'info',
                        'page' => 'warning',
                        'blog' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('result')
                    ->badge()
                    ->color(fn (?string $state): string => self::resultColor($state))
                    ->sortable(),
                TextColumn::make('status_code')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'warning',
                        $state >= 500 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('response_time_ms')
                    ->label('Load ms')
                    ->badge()
                    ->color(fn (?int $state): string => ((int) ($state ?? 0)) >= self::slowThreshold() ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('speed_classification')
                    ->label('Speed')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state ? (self::speedOptions()[$state] ?? 'Unknown') : null)
                    ->color(fn (?string $state): string => self::speedColor($state))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('error_reason')
                    ->label('Reason')
                    ->state(fn (SiteAuditResult $record): ?string => self::reasonSummary($record))
                    ->searchable()
                    ->limit(44)
                    ->tooltip(fn (SiteAuditResult $record): ?string => self::reasonTooltip($record))
                    ->extraAttributes([
                        'style' => 'max-width: 16rem; white-space: nowrap;',
                    ])
                    ->toggleable(),
                TextColumn::make('shopify_resource_status')
                    ->label('Shopify')
                    ->badge()
                    ->placeholder('-')
                    ->color(fn (?string $state): string => match ($state) {
                        'not_found', 'lookup_failed' => 'danger',
                        'draft', 'archived', 'blog_found_article_not_verified' => 'warning',
                        'active', 'found' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('final_url')
                    ->label('Final URL')
                    ->copyable()
                    ->limit(70)
                    ->tooltip(fn (SiteAuditResult $record): ?string => $record->final_url)
                    ->url(fn (SiteAuditResult $record): ?string => $record->final_url)
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn (SiteAuditResult $record): ?string => $record->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result')
                    ->options(self::resultOptions()),
                SelectFilter::make('resource_type')
                    ->label('Resource Type')
                    ->options(self::resourceTypeOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'siteAuditUrl',
                            fn (Builder $urlQuery): Builder => $urlQuery->where('resource_type', $value),
                        );
                    }),
                SelectFilter::make('audit_type')
                    ->label('Audit Type')
                    ->options([
                        SiteAuditRun::TYPE_SCHEDULED => 'Scheduled',
                        SiteAuditRun::TYPE_MANUAL => 'Manual',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'siteAuditRun',
                            fn (Builder $runQuery): Builder => $runQuery->where('type', $value),
                        );
                    }),
                Filter::make('slow')
                    ->label('Slow Pages')
                    ->query(fn (Builder $query): Builder => $query->where('response_time_ms', '>=', self::slowThreshold())),
                SelectFilter::make('speed_classification')
                    ->label('Speed')
                    ->options(self::speedOptions()),
            ])
            ->headerActions([
                ...self::reportLinkActions(),
                ExportAction::make()
                    ->label('Export CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->exporter(SiteAuditResultExporter::class),
            ])
            ->actions([
                Action::make('recheck')
                    ->label('Recheck')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (SiteAuditResult $record): void {
                        $auditUrl = $record->siteAuditUrl;

                        if (! $auditUrl) {
                            self::sendNotification(Notification::make()
                                ->title('URL not found')
                                ->body('The original audit URL no longer exists.')
                                ->warning());

                            return;
                        }

                        $run = app(SiteAuditRunnerService::class)->runUrl($auditUrl, SiteAuditRun::TYPE_MANUAL);

                        self::sendNotification(Notification::make()
                            ->title('URL check queued')
                            ->body("Audit run #{$run->id} queued for {$auditUrl->url}.")
                            ->success());
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('recheckSelected')
                        ->label('Recheck Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $urlIds = $records
                                ->pluck('site_audit_url_id')
                                ->map(fn ($id): int => (int) $id)
                                ->unique()
                                ->values()
                                ->all();

                            if ($urlIds === []) {
                                self::sendNotification(Notification::make()
                                    ->title('Nothing queued')
                                    ->body('No audit URLs were selected.')
                                    ->warning());

                                return;
                            }

                            $run = app(SiteAuditRunnerService::class)->run(SiteAuditRun::TYPE_MANUAL, $urlIds);

                            self::sendNotification(Notification::make()
                                ->title('URL checks queued')
                                ->body("Audit run #{$run->id} queued {$run->total_urls} URL check(s).")
                                ->success());
                        }),
                    ExportBulkAction::make()
                        ->exporter(SiteAuditResultExporter::class),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['siteAuditRun', 'siteAuditUrl']);
    }

    public static function latestCompletedRun(): ?SiteAuditRun
    {
        return SiteAuditRun::query()
            ->where('status', SiteAuditRun::STATUS_COMPLETED)
            ->latest('completed_at')
            ->latest('id')
            ->first();
    }

    public static function slowThreshold(): int
    {
        return (int) config('site-audit.slow_threshold_ms', 3000);
    }

    public static function resultOptions(): array
    {
        return [
            SiteAuditResult::RESULT_OK => 'OK',
            SiteAuditResult::RESULT_REDIRECT => 'Redirect',
            SiteAuditResult::RESULT_BROKEN => 'Broken',
            SiteAuditResult::RESULT_SERVER_ERROR => 'Server Error',
            SiteAuditResult::RESULT_TIMEOUT => 'Timeout',
            SiteAuditResult::RESULT_SSL_ERROR => 'SSL Error',
            SiteAuditResult::RESULT_FAILED => 'Failed',
        ];
    }

    public static function speedOptions(): array
    {
        return SiteAuditResult::speedLabels();
    }

    public static function speedColor(?string $speed): string
    {
        return match ($speed) {
            SiteAuditResult::SPEED_GOOD => 'success',
            SiteAuditResult::SPEED_ACCEPTABLE => 'info',
            SiteAuditResult::SPEED_SLOW => 'warning',
            SiteAuditResult::SPEED_VERY_SLOW => 'danger',
            default => 'gray',
        };
    }

    public static function resourceTypeOptions(): array
    {
        return [
            'product' => 'Product',
            'collection' => 'Collection',
            'page' => 'Page',
            'blog' => 'Blog',
            'unknown' => 'Unknown',
        ];
    }

    public static function resultColor(?string $result): string
    {
        return match ($result) {
            SiteAuditResult::RESULT_OK => 'success',
            SiteAuditResult::RESULT_REDIRECT => 'warning',
            SiteAuditResult::RESULT_BROKEN,
            SiteAuditResult::RESULT_SERVER_ERROR,
            SiteAuditResult::RESULT_TIMEOUT,
            SiteAuditResult::RESULT_SSL_ERROR,
            SiteAuditResult::RESULT_FAILED => 'danger',
            default => 'gray',
        };
    }

    public static function reasonSummary(SiteAuditResult $record): ?string
    {
        $reason = trim((string) ($record->error_reason ?? ''));
        $error = trim((string) ($record->error_message ?? ''));

        $source = $reason !== '' ? $reason : $error;

        if ($source === '') {
            return null;
        }

        $lower = strtolower($source);

        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'curl error 6') || str_contains($lower, 'dns host not resolved')) {
            return 'DNS host not resolved';
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'Request timed out';
        }

        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
            return 'SSL/certificate error';
        }

        if (str_contains($lower, 'redirect loop') || str_contains($lower, 'following redirects')) {
            return 'Redirect chain failed';
        }

        if (str_contains($lower, 'handle not found in shopify')) {
            return 'Shopify handle not found';
        }

        if (str_contains($lower, 'exists in shopify')) {
            return 'Exists in Shopify; storefront failed';
        }

        if (str_contains($lower, 'returned http 404') || str_contains($lower, 'returned http 410')) {
            return 'Not found on storefront';
        }

        if (str_contains($lower, 'server-side error') || str_contains($lower, 'returned http 5')) {
            return 'Server error';
        }

        return Str::limit($source, 44);
    }

    public static function reasonTooltip(SiteAuditResult $record): ?string
    {
        $parts = collect([
            trim((string) ($record->error_reason ?? '')),
            trim((string) ($record->error_message ?? '')),
        ])->filter()->unique()->values();

        return $parts->isEmpty() ? null : $parts->implode("\n\n");
    }

    public static function reportLinkActions(): array
    {
        return [
            Action::make('viewLatestReport')
                ->label('Latest Report')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->url(fn (): string => self::getUrl('latest')),
            Action::make('viewBrokenUrls')
                ->label('Broken URLs')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(fn (): string => self::getUrl('broken')),
            Action::make('viewRedirects')
                ->label('Redirects')
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('warning')
                ->url(fn (): string => self::getUrl('redirects')),
            Action::make('viewSlowUrls')
                ->label('Slow URLs')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->url(fn (): string => self::getUrl('slow')),
        ];
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
            'index' => Pages\ListSiteAuditResults::route('/'),
            'latest' => Pages\ListLatestSiteAuditResults::route('/latest'),
            'broken' => Pages\ListBrokenSiteAuditResults::route('/broken'),
            'redirects' => Pages\ListRedirectSiteAuditResults::route('/redirects'),
            'slow' => Pages\ListSlowSiteAuditResults::route('/slow'),
        ];
    }

    private static function sendNotification(Notification $notification): void
    {
        AdminNotification::send($notification);
    }
}
