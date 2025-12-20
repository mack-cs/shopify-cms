<?php

namespace App\Services;

final class RowKey
{
    public static function variantKey(array $row): ?string
    {
        $sku = trim((string)($row[HeaderStore::VARIANT_SKU] ?? ''));
        if ($sku !== '') return $sku;

        // fallback signature if SKU missing
        $o1 = (string)($row[HeaderStore::OPTION1_VALUE] ?? '');
        $o2 = (string)($row[HeaderStore::OPTION2_VALUE] ?? '');
        $o3 = (string)($row[HeaderStore::OPTION3_VALUE] ?? '');
        $sig = "o1={$o1}|o2={$o2}|o3={$o3}";
        return trim($sig) !== 'o1=|o2=|o3=' ? $sig : null;
    }

    public static function imageKey(array $row): ?string
    {
        $src = trim((string)($row[HeaderStore::IMAGE_SRC] ?? ''));
        if ($src === '') return null;

        $pos = trim((string)($row[HeaderStore::IMAGE_POSITION] ?? ''));
        return $pos !== '' ? "{$src}|{$pos}" : $src;
    }
}
