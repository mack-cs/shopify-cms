<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One session mutex per stack, shared by membership, gallery, and retirement writers.
 * Locks are taken in ascending stack id order before an enclosing database transaction
 * and released only after that transaction commits or rolls back.
 */
final class StackSynchronizationBoundary
{
    /** @var list<ImageSyncMutex> */
    private array $locks = [];

    /**
     * @param  array<int, int>  $stackIds
     */
    public function acquireForStacks(array $stackIds): self
    {
        $ids = collect($stackIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $acquired = [];
        try {
            foreach ($ids as $id) {
                $mutex = new ImageSyncMutex;
                if (! $mutex->acquire($id)) {
                    throw new RuntimeException('Another image operation is running for stack '.$id.'. The change was not saved.');
                }
                $acquired[] = $mutex;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($acquired) as $mutex) {
                $mutex->release();
            }
            throw $exception;
        }
        $held = new self;
        $held->locks = $acquired;

        return $held;
    }

    public function acquireForMembershipDraft(NewProductDraft $draft): self
    {
        $stackId = $this->stackIdForDraft($draft);
        if ($stackId === null) {
            return new self;
        }

        return $this->acquireForStacks([$stackId]);
    }

    /**
     * @param  array<int, int>  $productIds
     */
    public function acquireForProducts(array $productIds): self
    {
        $stackIds = [];
        foreach ($productIds as $productId) {
            foreach ($this->stackIdsForComponent((int) $productId) as $stackId) {
                $stackIds[] = $stackId;
            }
        }

        return $this->acquireForStacks($stackIds);
    }

    public function acquireForComponent(int $productId): self
    {
        return $this->acquireForStacks($this->stackIdsForComponent($productId));
    }

    public function ownsMembershipDraft(NewProductDraft $draft): bool
    {
        $stackId = $this->stackIdForDraft($draft);

        return $stackId === null || ImageSyncMutex::ownsProduct($stackId);
    }

    public function prepareMembershipWrite(NewProductDraft $draft): ?self
    {
        if ($this->stackIdForDraft($draft) === null) {
            return null;
        }
        if (DB::transactionLevel() > 0) {
            if (! $this->ownsMembershipDraft($draft)) {
                $this->releaseAfterEnclosingTransaction($this->acquireForMembershipDraft($draft));
            }

            return null;
        }

        return $this->acquireForMembershipDraft($draft);
    }

    public function prepareGalleryWrite(int $productId): ?self
    {
        if ($productId < 1 || $this->stackIdsForComponent($productId) === []) {
            return null;
        }
        if (DB::transactionLevel() > 0) {
            if (! $this->ownsComponent($productId)) {
                $this->releaseAfterEnclosingTransaction($this->acquireForComponent($productId));
            }

            return null;
        }

        return $this->acquireForComponent($productId);
    }

    private function releaseAfterEnclosingTransaction(self $held): void
    {
        $released = false;
        $release = static function () use ($held, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $held->release();
        };
        DB::afterCommit($release);
        DB::connection()->afterRollBack($release);
    }

    public function ownsComponent(int $productId): bool
    {
        foreach ($this->stackIdsForComponent($productId) as $stackId) {
            if (! ImageSyncMutex::ownsProduct($stackId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public function stackIdsForComponent(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }
        $product = Product::query()->find($productId);
        if ($product instanceof Product && $product->isStackProduct()) {
            return [(int) $product->id];
        }

        return array_values(app(StackComponentCatalog::class)->stackIdsForComponent($productId));
    }

    public function release(): void
    {
        foreach (array_reverse($this->locks) as $lock) {
            $lock->release();
        }
        $this->locks = [];
    }

    public function stackIdForDraft(NewProductDraft $draft): ?int
    {
        $shopifyId = trim((string) $draft->shopify_id);
        $handle = trim((string) $draft->handle);
        if ($shopifyId === '' && $handle === '') {
            return null;
        }
        $stack = Product::query()
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $shopifyId !== '' ? $query->orWhere('handle', $handle) : $query->where('handle', $handle);
                }
            })
            ->orderBy('id')
            ->get()
            ->first(fn (Product $product): bool => $product->isStackProduct());

        return $stack instanceof Product ? (int) $stack->id : null;
    }
}
