<?php

namespace App\Services;

final class ShopYourVibeVisibleProducts
{
    public static function visible(array $products): array
    {
        return array_values(array_filter($products, fn (array $product): bool =>
            strtolower(trim((string) ($product['status'] ?? ''))) !== 'archived'));
    }

    public static function fullOrder(array $products, array $ids): array
    {
        $visibleIds = array_column(self::visible($products), 'id');
        $expected = $visibleIds;
        $actual = $ids;
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new \RuntimeException('Include every visible product exactly once.');
        }

        $position = 0;
        $order = [];
        $visible = array_fill_keys($visibleIds, true);
        foreach ($products as $product) {
            $order[] = isset($visible[$product['id']]) ? $ids[$position++] : $product['id'];
        }

        return $order;
    }
}
