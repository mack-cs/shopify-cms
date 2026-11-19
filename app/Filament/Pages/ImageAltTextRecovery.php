<?php

namespace App\Filament\Pages;

use App\Enums\RolesEnum;
use App\Jobs\ProductImageBackupImagesJob;
use App\Jobs\PublishApprovedImageAltTextJob;
use App\Jobs\SelectedProductImageShopifySyncJob;
use App\Models\Image;
use App\Services\ImageAltApprovalEvidence;
use App\Services\ImageAltRecovery;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ImageUploadOperations;
use App\Services\ShopifyMediaImageResolver;
use App\Services\VariantImageSynchronizer;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ImageAltTextRecovery extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Products';

    protected static ?string $navigationLabel = 'Image Alt Review';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static string $view = 'filament.pages.image-alt-text-recovery';

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active && (Auth::user()?->hasAnyRole([RolesEnum::Admin->value, RolesEnum::SuperAdmin->value]) ?? false);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    private function perform(callable $operation): void
    {
        abort_unless(self::canAccess(), 403);
        try {
            $operation();
            Notification::make()->title('Review action completed')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Image needs review')->body($e->getMessage())->warning()->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(Image::query()->active()->with('product', 'imageAsset'))->defaultSort('id')->columns([
            Tables\Columns\TextColumn::make('id')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('product.handle')->searchable(),
            Tables\Columns\ImageColumn::make('src')->label('Backup photograph')->state(fn (Image $record) => $record->imageAsset?->isAvailable() ? $record->backupPublicUrl() : $record->src),
            Tables\Columns\TextColumn::make('position'),
            Tables\Columns\TextColumn::make('ownership')->default('Unresolved on stacks'),
            Tables\Columns\TextColumn::make('alt_text')->wrap()->limit(100),
            Tables\Columns\TextColumn::make('alt_text_status')->badge(),
            Tables\Columns\TextColumn::make('review')->state(fn (Image $record) => app(ImageAltRecovery::class)->issues($record))->wrap(),
            Tables\Columns\TextColumn::make('shopify_alt_sync_error')->wrap()->limit(160),
            Tables\Columns\TextColumn::make('shopify_image_sync_error')->wrap()->limit(160),
        ])->filters([
            Tables\Filters\SelectFilter::make('alt_text_status')->options(['pending_review' => 'Pending review', 'approved' => 'Approved', 'failed' => 'Failed', 'awaiting_confirmation' => 'Awaiting confirmation', 'published' => 'Published']),
            Tables\Filters\Filter::make('unresolved_ownership')->query(fn ($query) => $query->whereHas('product', fn ($p) => $p->where(fn ($stack) => $stack->where('is_bundle', true)->orWhereRaw('lower(tags) like ?', ['%stack%'])->orWhereRaw('lower(tags) like ?', ['%bundle%'])))
                ->where(fn ($ownership) => $ownership->whereNull('ownership')->orWhereNotIn('ownership', [ImageOwnership::STACK_OWNED, ImageOwnership::COMPONENT_INHERITED]))),
            Tables\Filters\Filter::make('missing_backup')->query(fn ($query) => $query->whereNull('image_asset_id')),
            Tables\Filters\Filter::make('unresolved_identity')->query(fn ($query) => $query->where(fn ($x) => $x->whereNull('shopify_id')->orWhere('shopify_identity_unresolved', true))),
        ])->actions([
            Tables\Actions\Action::make('inspect')->label('Verify media')->action(fn (Image $record) => $this->perform(function () use ($record) {
                $decision = app(ShopifyMediaImageResolver::class)->diagnose($record);
                Notification::make()->title($decision['classification'])->body($decision['evidence'])->send();
            })),
            Tables\Actions\Action::make('backup')->label('Back up')->requiresConfirmation()->action(fn (Image $record) => $this->perform(fn () => ProductImageBackupImagesJob::dispatch([$record->id], Auth::id()))),
            Tables\Actions\Action::make('classify')->visible(fn (Image $record) => $record->product?->isStackProduct())
                ->form([Select::make('ownership')->options([ImageOwnership::STACK_OWNED => 'Stack owned', ImageOwnership::COMPONENT_INHERITED => 'Inherited component'])->required(), TextInput::make('source')->label('Component CMS image ID (inherited only)')->numeric(), Textarea::make('reason')->minLength(20)->required()])
                ->action(fn (Image $record, array $data) => $this->perform(fn () => app(ImageAltRecovery::class)->classify($record, $data['ownership'], isset($data['source']) ? (int) $data['source'] : null, Auth::id(), $data['reason']))),
            Tables\Actions\Action::make('description')->label('Edit alt')->form([Textarea::make('alt')->required()->maxLength(255)->default(fn (Image $record) => $record->alt_text)])
                ->action(fn (Image $record, array $data) => $this->perform(fn () => app(ImageAltTextBackfillService::class)->markPendingReview($record, $data['alt']))),
            Tables\Actions\Action::make('approve')->label('Approve sentence')->requiresConfirmation()
                ->modalDescription('Approve this exact sentence for this backup photograph. This does not publish or replace media.')
                ->form([Textarea::make('review_sentence')->label('Sentence being approved')->default(fn (Image $record) => $record->alt_text)->readOnly()->required(), Hidden::make('review_asset_id')->default(fn (Image $record) => $record->image_asset_id)->required(), Textarea::make('reason')->required()->minLength(20)])
                ->action(fn (Image $record, array $data) => $this->perform(fn () => app(ImageAltApprovalEvidence::class)->approve($record, Auth::id(), $data['reason'], $data['review_sentence'], (int) $data['review_asset_id']))),
            Tables\Actions\Action::make('publish')->label('Publish / retry alt')->requiresConfirmation()
                ->action(fn (Image $record) => $this->perform(fn () => PublishApprovedImageAltTextJob::dispatch([$record->id], Auth::id()))),
            Tables\Actions\Action::make('adopt_upload')->label('Review uploaded file')
                ->visible(fn (Image $record) => app(ImageUploadOperations::class)->active($record) !== null)
                ->modalDescription('Choose the exact independent file after reviewing Shopify upload history. Matching bytes alone do not prove which uncertain request created a file. This action sends no Shopify mutation.')
                ->form([TextInput::make('media_id')->label('Reviewed MediaImage GID')->required()->regex('~^gid://shopify/MediaImage/\d+$~'), Textarea::make('reason')->required()->minLength(20)->maxLength(180)])
                ->action(fn (Image $record, array $data) => $this->perform(function () use ($record, $data): void {
                    $operation = app(ImageUploadOperations::class)->active($record);
                    if (! $operation || Artisan::call('images:resolve-upload', ['operation' => $operation->id, '--adopt-media-id' => $data['media_id'], '--operator' => Auth::id(), '--reason' => $data['reason']]) !== 0) {
                        throw new \RuntimeException(Artisan::output());
                    }
                })),
            Tables\Actions\Action::make('resolve_variant')->label('Reconcile variant')
                ->visible(fn (Image $record) => DB::table('image_variant_operations')->where('image_id', $record->id)->whereNotNull('active_key')->exists())
                ->form([Toggle::make('abandon')->label('Abandon tracking without changing Shopify'), Textarea::make('reason')->required()->minLength(20)->maxLength(180)])
                ->action(fn (Image $record, array $data) => $this->perform(function () use ($record, $data): void {
                    $ids = DB::table('image_variant_operations')->where('image_id', $record->id)->whereNotNull('active_key')->pluck('id');
                    foreach ($ids as $id) {
                        app(VariantImageSynchronizer::class)->recover((int) $id, Auth::id(), $data['reason'], (bool) ($data['abandon'] ?? false));
                    }
                })),
            Tables\Actions\Action::make('prepare_rebuild')->label('Prepare media rebuild')->requiresConfirmation()
                ->modalDescription('Preserves the old Shopify file and all descriptions. Clears an unusable/shared local media link after verification. Photograph publishing still requires normal product approval.')
                ->form([Textarea::make('reason')->required()->minLength(20)->maxLength(180)])
                ->action(fn (Image $record, array $data) => $this->perform(fn () => app(ImageAltRecovery::class)->prepareRebuild($record, Auth::id(), $data['reason']))),
            Tables\Actions\Action::make('publish_photo')->label('Publish approved photograph')->requiresConfirmation()
                ->modalDescription('Uses the existing product approval and image upload safeguards. It does not delete old media.')
                ->action(fn (Image $record) => $this->perform(fn () => SelectedProductImageShopifySyncJob::dispatch($record->product_id, [$record->id], Auth::id(), 'Operator reviewed image recovery'))),
            Tables\Actions\Action::make('confirm')->label('Read back pending alt')->visible(fn (Image $record) => $record->alt_confirmation_media_id !== null)
                ->action(fn (Image $record) => $this->perform(function () use ($record) {
                    if (Artisan::call('images:resolve-alt-confirmation', ['image' => $record->id, '--operator' => Auth::id()]) !== 0) {
                        throw new \RuntimeException(Artisan::output());
                    }
                })),
            Tables\Actions\Action::make('abandon')->label('Abandon pending request')->color('warning')->visible(fn (Image $record) => $record->alt_confirmation_media_id !== null)
                ->requiresConfirmation()->modalDescription('Reads Shopify and abandons local tracking only. No fileUpdate is sent; the approved sentence and history are preserved.')
                ->form([Textarea::make('reason')->required()->minLength(20)->maxLength(180)])
                ->action(fn (Image $record, array $data) => $this->perform(function () use ($record, $data) {
                    if (Artisan::call('images:resolve-alt-confirmation', ['image' => $record->id, '--operator' => Auth::id(), '--abandon' => true, '--reason' => $data['reason']]) !== 0) {
                        throw new \RuntimeException(Artisan::output());
                    }
                })),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('publish')->label('Publish approved alt')->requiresConfirmation()
                ->action(function ($records): void {
                    $this->perform(fn () => PublishApprovedImageAltTextJob::dispatch($records->pluck('id')->all(), Auth::id()));
                }),
        ])->selectCurrentPageOnly();
    }
}
