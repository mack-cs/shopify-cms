<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Product;
use App\Services\ImagePhotographEvidence;
use App\Services\ImageRecoveryOperator;
use App\Services\ImageSyncMutex;
use App\Services\ProductShopifyUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reads Shopify only. Never authorizes replay of an uncertain remote mutation. */
final class ResolveMediaRetirement extends Command
{
    protected $signature = 'images:resolve-media-retirement {retirement} {--operator=} {--reason=} {--keep}';

    protected $description = 'Confirm absent stack media or explicitly retain the old photograph; never sends a Shopify mutation';

    public function handle(ImageRecoveryOperator $operators, ProductShopifyUpdater $updater): int
    {
        $mutex = new ImageSyncMutex;
        $held = false;
        try {
            $operator = $operators->authorize($this->option('operator'));
            $reason = $operators->reason($this->option('reason'));
            $intent = DB::table('inherited_media_retirements')->find($this->argument('retirement'));
            if (! $intent || ! in_array($intent->status, ['pending', 'mutating'], true)) {
                throw new RuntimeException('Choose an unresolved retirement.');
            }
            $held = $mutex->acquire((int) $intent->stack_product_id);
            if (! $held) {
                throw new RuntimeException('Another image operation is running.');
            }
            $stack = Product::findOrFail($intent->stack_product_id);
            $absent = $updater->stackMediaAbsent($stack, $intent->old_media_id);
            $mutex->assertOwned();
            if (! $absent && ! $this->option('keep')) {
                throw new RuntimeException('The association is still present. No replay was authorized. Reconcile later or explicitly retain it with --keep.');
            }
            DB::transaction(function () use ($intent, $operator, $reason, $absent): void {
                $live = DB::table('inherited_media_retirements')->where('id', $intent->id)->lockForUpdate()->first();
                if (! $live || $live->status !== $intent->status || $live->updated_at !== $intent->updated_at) {
                    throw new RuntimeException('The retirement changed during recovery; read it again.');
                }
                if ($image = Image::find($intent->image_id)) {
                    app(ImagePhotographEvidence::class)->archive($image, 'Operator reconciled a stack retirement');
                }
                DB::table('image_alt_review_events')->insert([
                    'image_id' => $intent->image_id, 'user_id' => $operator->id, 'action' => 'resolve_retirement',
                    'reason' => $reason, 'snapshot' => json_encode($intent, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                DB::table('inherited_media_retirements')->where('id', $intent->id)->update([
                    'status' => $absent ? 'confirmed' : 'retained', 'resolution' => $absent ? 'operator_confirmed_absent' : 'operator_retained',
                    'last_error' => null, 'updated_at' => now(),
                ]);
            });
            $this->info($absent ? 'Remote absence confirmed. No mutation sent.' : 'Old photograph retained by explicit decision. No mutation sent.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($held) {
                $mutex->release();
            }
        }
    }
}
