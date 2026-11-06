<?php

namespace App\Services\EngravedBeads;

/**
 * Converts finished-product sales into physical bead demand.
 * Shopify's original unit is included once inside the bead count.
 * Stack-attributed component units are left unchanged so they are not counted again.
 */
final class EngravedBeadDemand
{
    /**
     * @param  array<string, mixed>  $sales
     * @return array<string, mixed>
     */
    public function scaleDirectSales(array $sales, int $beads): array
    {
        if ($beads < 1) {
            return $sales;
        }

        foreach (['direct_gross', 'direct_refunded', 'direct_net'] as $key) {
            $sales[$key] = (int) ($sales[$key] ?? 0) * $beads;
        }

        $sales['gross'] = (int) ($sales['direct_gross'] ?? 0) + (int) ($sales['stack_gross'] ?? 0);
        $sales['refunded'] = (int) ($sales['direct_refunded'] ?? 0) + (int) ($sales['stack_refunded'] ?? 0);
        $sales['net'] = (int) ($sales['direct_net'] ?? 0) + (int) ($sales['stack_net'] ?? 0);

        return $sales;
    }
}
