<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\ProductResource;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftSeeder;
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
    /** @var array<int, string> */
    protected array $savedDraftAttributes = [];

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $this->refreshDraftFromLinkedProduct();
        $this->acquireEditLock();

        if ($this->record instanceof NewProductDraft && $this->record->isPendingApproval()) {
            Notification::make()
                ->title('Draft pending approval')
                ->body('This draft already has an approval recorded and is pending the current approval cycle. Editing is disabled until that approval cycle is completed.')
                ->warning()
                ->persistent()
                ->send();
        }

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
        $data = NewProductDraftResource::mutateDraftFormData($data, $this->record);
        $this->savedDraftAttributes = $this->syncableDraftAttributes(array_keys($data));

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncSavedDraftFieldsToProduct();
        $this->refreshEditLock();
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->disabled(fn (): bool => $this->isLockedByAnotherUser() || $this->isPendingApprovalLocked())
            ->tooltip(fn (): ?string => $this->isPendingApprovalLocked()
                ? 'This draft is already pending approval. Finish the current approval cycle before editing it again.'
                : ($this->hasBlockingShopifyWarnings()
                ? 'Conflicting field changes will not be applied until you resolve the Shopify warnings at the top. Non-conflicting changes can still be saved.'
                : ($this->isLockedByAnotherUser()
                    ? 'Another user is actively editing this draft. Saving is locked until that edit session expires or is released.'
                    : null)));
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
            Actions\Action::make('withdrawFromApproval')
                ->label('Withdraw Approval')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record instanceof NewProductDraft && $this->record->isPendingApproval())
                ->action(function (): void {
                    if (!$this->record instanceof NewProductDraft) {
                        return;
                    }

                    $result = NewProductDraftResource::withdrawDraftFromApproval($this->record, (int) Auth::id());
                    $this->record = $this->record->fresh(['editingUser']) ?? $this->record;
                    $this->refreshEditLock();

                    Notification::make()
                        ->title('Draft withdrawn from approval')
                        ->body("Removed {$result['removed']} approval record(s).")
                        ->warning()
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

    public function resolveSingleShopifyWarningUsingShopify(string $field): void
    {
        if (!$this->record instanceof NewProductDraft) {
            return;
        }

        $result = NewProductDraftResource::resolveSingleShopifyWarning(
            $this->record->fresh() ?? $this->record,
            $field,
            'shopify'
        );

        if (!$result['resolved']) {
            Notification::make()
                ->title('Warning not resolved')
                ->body('That Shopify warning could not be found. Refresh the page and try again.')
                ->warning()
                ->send();

            return;
        }

        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;

        Notification::make()
            ->title('Shopify value applied')
            ->success()
            ->send();

        $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
    }

    public function resolveSingleShopifyWarningKeepingDraft(string $field): void
    {
        if (!$this->record instanceof NewProductDraft) {
            return;
        }

        $result = NewProductDraftResource::resolveSingleShopifyWarning(
            $this->record->fresh() ?? $this->record,
            $field,
            'draft'
        );

        if (!$result['resolved']) {
            Notification::make()
                ->title('Warning not resolved')
                ->body('That Shopify warning could not be found. Refresh the page and try again.')
                ->warning()
                ->send();

            return;
        }

        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;

        Notification::make()
            ->title('Draft value kept')
            ->body($result['synced'] ? 'That field was synced back to Products.' : null)
            ->success()
            ->send();

        $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
    }

    private function hasBlockingShopifyWarnings(): bool
    {
        return (($this->record?->shopifySyncWarningCount() ?? 0) > 0);
    }

    private function isPendingApprovalLocked(): bool
    {
        return $this->record instanceof NewProductDraft
            && $this->record->isPendingApproval();
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

    private function refreshDraftFromLinkedProduct(): void
    {
        if (!$this->record instanceof NewProductDraft) {
            return;
        }

        $product = null;

        $shopifyId = trim((string) ($this->record->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $product = Product::query()
                ->where('shopify_id', $shopifyId)
                ->first();
        }

        if (!$product instanceof Product) {
            $handle = trim((string) ($this->record->handle ?? ''));
            if ($handle !== '') {
                $product = Product::query()
                    ->where('handle', $handle)
                    ->first();
            }
        }

        if (!$product instanceof Product) {
            return;
        }

        $this->record = app(NewProductDraftSeeder::class)->upsertFromProduct(
            $product,
            Auth::id()
        );
    }

    /**
     * @param array<int, string> $attributes
     * @return array<int, string>
     */
    private function syncableDraftAttributes(array $attributes): array
    {
        $blocked = [
            'handle',
            'shopify_id',
            'origin',
            'created_by',
            'shopify_sync_warnings',
            'editing_user_id',
            'editing_started_at',
            'editing_expires_at',
            'approval_version',
        ];

        return array_values(array_filter(
            array_unique(array_map('strval', $attributes)),
            static fn (string $attribute): bool => $attribute !== '' && !in_array($attribute, $blocked, true)
        ));
    }

    private function syncSavedDraftFieldsToProduct(): void
    {
        if (!$this->record instanceof NewProductDraft) {
            return;
        }

        if ($this->savedDraftAttributes === []) {
            return;
        }

        app(NewProductDraftProductSync::class)->syncToExistingProduct(
            $this->record->fresh() ?? $this->record,
            ensureApprovalReset: true,
            attributes: $this->savedDraftAttributes
        );

        $this->savedDraftAttributes = [];
        $this->record = $this->record->fresh(['editingUser']) ?? $this->record;
    }
}
