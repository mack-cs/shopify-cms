<?php

namespace App\Observers;

use App\Models\Approval;
use App\Models\Product;
use App\Services\StackImageSync;

/**
 * Runs inside the approval's database transaction. The after-commit
 * approval observer authorizes this persisted snapshot and must not recapture it.
 */
class PublicationSnapshotObserver
{
    public function created(Approval $approval): void
    {
        $product = $approval->product()->first();
        if ($product instanceof Product) {
            app(StackImageSync::class)->capturePublicationSnapshot($product, $approval);
        }
    }
}
