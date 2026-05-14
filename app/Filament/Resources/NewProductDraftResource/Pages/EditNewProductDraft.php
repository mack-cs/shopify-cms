<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use App\Models\NewProductDraft;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class EditNewProductDraft extends EditRecord
{
    protected static string $resource = NewProductDraftResource::class;
    protected ?bool $hasUnsavedDataChangesAlert = true;
    protected int $editLockMinutes = 15;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $this->acquireEditLock();

        if ($this->hasBlockingShopifyWarnings()) {
            Notification::make()
                ->title('Shopify conflicts detected')
                ->body('This draft has unresolved Shopify sync warnings. Non-conflicting changes can still be saved, but conflicting field changes will not be applied until you resolve them at the top.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NewProductDraftResource::mutateDraftFormData($data, $this->record);
    }

    protected function afterSave(): void
    {
        $this->refreshEditLock();
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->disabled(fn (): bool => $this->isLockedByAnotherUser())
            ->tooltip(fn (): ?string => $this->hasBlockingShopifyWarnings()
                ? 'Conflicting field changes will not be applied until you resolve the Shopify warnings at the top. Non-conflicting changes can still be saved.'
                : ($this->isLockedByAnotherUser()
                    ? 'Another user is actively editing this draft. Saving is locked until that edit session expires or is released.'
                    : null));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refreshEditLock')
                ->label('Refresh Edit Lock')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => !$this->isLockedByAnotherUser())
                ->action(function (): void {
                    $this->refreshEditLock();
                }),
            Actions\Action::make('releaseEditLock')
                ->label('Done Editing')
                ->icon('heroicon-o-lock-open')
                ->color('gray')
                ->visible(fn (): bool => !$this->isLockedByAnotherUser() && $this->currentUserOwnsLock())
                ->action(function (): void {
                    $this->releaseEditLock();

                    Notification::make()
                        ->title('Edit lock released')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('editImagesAndVariants')
                ->label('Edit Product')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => $this->record?->product !== null)
                ->url(fn (): ?string => $this->record?->product
                    ? ProductResource::getUrl('edit', [
                        'record' => $this->record->product,
                        'activeRelationManager' => '1',
                    ]) . '#relationManager1'
                    : null),
        ];
    }

    private function hasBlockingShopifyWarnings(): bool
    {
        return (($this->record?->shopifySyncWarningCount() ?? 0) > 0);
    }

    public function refreshEditorLockStatus(): void
    {
        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;

        if (!$this->isLockedByAnotherUser()) {
            $this->refreshEditLock();
        }
    }

    public function editLockStatusHtml(): HtmlString
    {
        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;

        $editorName = e((string) ($this->record?->editingUser?->name ?? $this->record?->editingUser?->email ?? 'Another user'));
        $expiresAt = $this->record?->editing_expires_at?->diffForHumans();

        if ($this->isLockedByAnotherUser()) {
            $suffix = $expiresAt ? " Lock expires {$expiresAt}." : '';

            return new HtmlString(
                "<div class='rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-900'>"
                . "<strong>{$editorName}</strong> is actively editing this draft. Saving is locked for other users.{$suffix}"
                . '</div>'
            );
        }

        if ($this->currentUserOwnsLock()) {
            $suffix = $expiresAt ? " Lock refreshes until {$expiresAt} if this page stays open." : '';

            return new HtmlString(
                "<div class='rounded-xl border border-success-300 bg-success-50 px-4 py-3 text-sm text-success-900'>"
                . "You currently hold the edit lock for this draft.{$suffix}"
                . '</div>'
            );
        }

        return new HtmlString(
            "<div class='rounded-xl border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-800'>"
            . 'This draft is not currently locked by another user.'
            . '</div>'
        );
    }

    protected function acquireEditLock(): void
    {
        $userId = Auth::id();
        if (!$userId || !$this->record instanceof NewProductDraft) {
            return;
        }

        $this->record->clearExpiredEditLock($this->editLockMinutes);
        if (!$this->record->acquireEditLock($userId, $this->editLockMinutes)) {
            $this->record = $this->record->fresh(['editingUser']) ?? $this->record;

            Notification::make()
                ->title('Draft is being edited')
                ->body($this->lockedByAnotherUserMessage())
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;
    }

    protected function refreshEditLock(): void
    {
        $userId = Auth::id();
        if (!$userId || !$this->record instanceof NewProductDraft) {
            return;
        }

        $this->record->refreshEditLock($userId, $this->editLockMinutes);
        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;
    }

    protected function releaseEditLock(): void
    {
        $userId = Auth::id();
        if (!$this->record instanceof NewProductDraft) {
            return;
        }

        $this->record->releaseEditLock($userId);
        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;
    }

    protected function currentUserOwnsLock(): bool
    {
        $userId = Auth::id();

        return $userId !== null
            && (int) ($this->record?->editing_user_id ?? 0) === $userId
            && $this->record?->editing_expires_at !== null
            && $this->record->editing_expires_at->isFuture();
    }

    protected function isLockedByAnotherUser(): bool
    {
        return $this->record instanceof NewProductDraft
            && $this->record->isActivelyEditedByAnotherUser(Auth::id(), $this->editLockMinutes);
    }

    protected function lockedByAnotherUserMessage(): string
    {
        $editor = $this->record?->editingUser?->name ?: $this->record?->editingUser?->email ?: 'Another user';
        $expiresAt = $this->record?->editing_expires_at?->diffForHumans();

        return $expiresAt
            ? "{$editor} is actively editing this draft. The lock expires {$expiresAt}."
            : "{$editor} is actively editing this draft.";
    }
}
