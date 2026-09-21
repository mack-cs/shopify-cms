<?php

namespace App\Services\Procurement;

use App\Models\Variant;

/** @deprecated Use SupplierOrderSummaryService. */
final class SupplierOrderProjectionService
{
    public function __construct(private readonly SupplierOrderSummaryService $summary) {}

    public function projectVariant(Variant $variant, ?int $changedBy = null, string $source = 'cms:supplier-orders'): void
    {
        $this->summary->refreshVariant($variant, $changedBy, $source);
    }
}
