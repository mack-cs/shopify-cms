<?php

namespace App\Services;

final class SalePercentageCalculator
{
    public function percentage(mixed $price, mixed $compareAtPrice): ?float
    {
        if (! is_numeric((string) $price) || ! is_numeric((string) $compareAtPrice)) {
            return null;
        }
        $price = (float) $price;
        $compareAtPrice = (float) $compareAtPrice;
        if ($compareAtPrice <= 0 || $compareAtPrice <= $price) {
            return null;
        }

        return round((($compareAtPrice - $price) / $compareAtPrice) * 100, 2);
    }

    public function formatted(mixed $price, mixed $compareAtPrice): ?string
    {
        $percentage = $this->percentage($price, $compareAtPrice);

        return $percentage === null ? null : number_format($percentage, 2, '.', '').'%';
    }
}
