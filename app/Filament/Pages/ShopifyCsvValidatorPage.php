<?php

namespace App\Filament\Pages;

use App\Services\ShopifyCsvValidator;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ShopifyCsvValidatorPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Product Data';
    protected static ?string $navigationLabel = 'CSV Validator';
    protected static string $view = 'filament.pages.shopify-csv-validator';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Validate Shopify CSV')
                    ->schema([
                        FileUpload::make('file')
                            ->label('CSV File')
                            ->disk('public')
                            ->directory('validator')
                            ->acceptedFileTypes(['text/csv'])
                            ->required()
                            ->storeFiles(false),
                        Actions::make([
                            Action::make('validate')
                                ->label('Validate')
                                ->action(function (ShopifyCsvValidator $validator): void {
                                    $path = $this->resolveUploadedPath($this->data['file'] ?? null);
                                    if (!$path) {
                                        $notification = Notification::make()
                                            ->title('Missing file')
                                            ->danger();
                                        if ($user = Auth::user()) {
                                            $notification->sendToDatabase($user);
                                        }
                                        $notification->send();
                                        return;
                                    }

                                    if (is_file($path)) {
                                        $absolutePath = $path;
                                    } else {
                                        $disk = Storage::disk('public');
                                        if (!$disk->exists($path)) {
                                            $notification = Notification::make()
                                                ->title('File not found')
                                                ->danger();
                                            if ($user = Auth::user()) {
                                                $notification->sendToDatabase($user);
                                            }
                                            $notification->send();
                                            return;
                                        }

                                        $absolutePath = $disk->path($path);
                                    }
                                    $templatePath = storage_path('app/private/imports/products.csv');

                                    $result = $validator->validateAgainstTemplate($absolutePath, $templatePath);
                                    if ($result['valid']) {
                                        $notification = Notification::make()
                                            ->title('CSV looks valid')
                                            ->success();
                                        if ($user = Auth::user()) {
                                            $notification->sendToDatabase($user);
                                        }
                                        $notification->send();
                                        return;
                                    }

                                    $errors = $result['errors'];
                                    $preview = array_slice($errors, 0, 5);
                                    $moreCount = max(0, count($errors) - count($preview));
                                    $body = implode("\n", $preview);
                                    if ($moreCount > 0) {
                                        $body .= "\n...and {$moreCount} more.";
                                    }

                                    $notification = Notification::make()
                                        ->title('CSV validation failed')
                                        ->body($body)
                                        ->danger();
                                    if ($user = Auth::user()) {
                                        $notification->sendToDatabase($user);
                                    }
                                    $notification->send();
                                }),
                        ]),
                    ]),
            ]);
    }

    private function resolveUploadedPath(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile) {
            return $this->storeTempFile($value);
        }

        if (is_string($value)) {
            $stored = $this->storeTempIfNeeded($value);
            return $stored ?? $value;
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['path']) && is_string($value['path'])) {
            $stored = $this->storeTempIfNeeded($value['path']);
            return $stored ?? $value['path'];
        }

        foreach ($value as $key => $item) {
            if ($item instanceof TemporaryUploadedFile) {
                return $this->storeTempFile($item);
            }

            if (is_string($key)) {
                $stored = $this->storeTempIfNeeded($key);
                if ($stored) {
                    return $stored;
                }
            }

            $resolved = $this->resolveUploadedPath($item);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private function storeTempIfNeeded(string $value): ?string
    {
        if (!str_starts_with($value, 'livewire-file:')) {
            try {
                $tmp = TemporaryUploadedFile::createFromLivewire($value);
                return $this->storeTempFile($tmp);
            } catch (Throwable) {
                return null;
            }
        }

        try {
            $tmp = TemporaryUploadedFile::createFromLivewire($value);
            return $this->storeTempFile($tmp);
        } catch (Throwable) {
            return null;
        }
    }

    private function storeTempFile(TemporaryUploadedFile $tmp): string
    {
        $name = $tmp->getClientOriginalName() ?: $tmp->getFilename();
        $stored = $tmp->storeAs('validator', $name, 'public');
        return Storage::disk('public')->path($stored);
    }
}
