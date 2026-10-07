@php
    $movement = $product['movement_classification'] ?? null;
    $movementLabel = match ($movement) {
        'FAST_MOVING' => 'Fast Moving',
        'MEDIUM_MOVING' => 'Medium Moving',
        'SLOW_MOVING' => 'Slow Moving',
        'NEW_PRODUCT' => 'New Product',
        'NO_SALES' => 'No Sales',
        default => null,
    };
@endphp

@if ($product['is_sold_out'] ?? false)
    <span class="syv-product-badge syv-product-badge-sold">Sold Out</span>
@elseif ($product['is_low_stock'] ?? false)
    <span class="syv-product-badge syv-product-badge-low">Low Stock</span>
@endif

@if ($movementLabel)
    <span @class([
        'syv-product-badge',
        'syv-product-badge-movement',
        'syv-product-badge-fast' => $movement === 'FAST_MOVING',
        'syv-product-badge-medium' => $movement === 'MEDIUM_MOVING',
        'syv-product-badge-slow' => $movement === 'SLOW_MOVING',
        'syv-product-badge-new' => $movement === 'NEW_PRODUCT',
        'syv-product-badge-none' => $movement === 'NO_SALES',
    ])>{{ $movementLabel }}</span>
@endif
