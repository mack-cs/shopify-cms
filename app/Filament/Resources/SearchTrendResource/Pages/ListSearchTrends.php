<?php

namespace App\Filament\Resources\SearchTrendResource\Pages;

use App\Filament\Resources\SearchTrendResource;
use App\Models\SearchTrend;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class ListSearchTrends extends ListRecords
{
    protected static string $resource = SearchTrendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('importCsv')
                ->label('Import CSV')
                ->color('info')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV File')
                        ->required()
                        ->disk('local')
                        ->directory('imports/search-trends')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    TextInput::make('period_label')
                        ->label('Period (optional)')
                        ->helperText('Used when the CSV does not include a period_label column.'),
                ])
                ->action(function (array $data): void {
                    $filePath = $data['file'] ?? null;
                    if (!$filePath) {
                        Notification::make()
                            ->title('No file selected')
                            ->danger()
                            ->send();
                        return;
                    }

                    $path = Storage::disk('local')->path($filePath);
                    $csv = Reader::createFromPath($path);
                    $csv->setHeaderOffset(0);

                    $records = $csv->getRecords();
                    $created = 0;
                    $skipped = 0;

                    foreach ($records as $row) {
                        $row = array_change_key_case($row, CASE_LOWER);

                        $period = trim((string) ($row['period_label'] ?? $data['period_label'] ?? ''));
                        $type = strtolower(trim((string) ($row['type'] ?? '')));
                        $label = trim((string) ($row['label'] ?? $row['query'] ?? $row['page'] ?? ''));

                        if ($period === '' || $type === '' || $label === '') {
                            $skipped++;
                            continue;
                        }

                        if (!in_array($type, ['query', 'page'], true)) {
                            $type = str_contains($type, 'page') ? 'page' : (str_contains($type, 'query') ? 'query' : $type);
                        }

                        $clicks = self::toInt($row['clicks'] ?? null);
                        $impressions = self::toInt($row['impressions'] ?? null);
                        $ctr = self::toDecimal($row['ctr'] ?? null);
                        $position = self::toDecimal($row['position'] ?? null);

                        SearchTrend::create([
                            'period_label' => $period,
                            'type' => $type,
                            'label' => $label,
                            'clicks' => $clicks,
                            'impressions' => $impressions,
                            'ctr' => $ctr,
                            'position' => $position,
                        ]);
                        $created++;
                    }

                    Notification::make()
                        ->title('Import complete')
                        ->body("Created: {$created}, Skipped: {$skipped}")
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function toInt(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        $clean = preg_replace('/[^\d\-]/', '', (string) $value);
        return (int) ($clean === '' ? 0 : $clean);
    }

    private static function toDecimal(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        $clean = str_replace(['%', ' '], '', (string) $value);
        $clean = str_replace(',', '.', $clean);
        $clean = preg_replace('/[^0-9\.\-]/', '', $clean);
        return (float) ($clean === '' ? 0.0 : $clean);
    }
}
