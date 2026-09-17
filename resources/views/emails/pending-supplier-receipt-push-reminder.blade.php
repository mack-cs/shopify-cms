@php
    $line = $receipt->line;
    $order = $line?->order;
    $variant = $line?->variant;
    $product = $variant?->product;
@endphp

<p>A supplier receipt has been staged for more than 30 minutes and is still pending push to Shopify.</p>

<ul>
    <li><strong>GRV:</strong> {{ $receipt->grv_number ?: '-' }}</li>
    <li><strong>Order ID:</strong> {{ $order?->order_number ?: 'Legacy order' }}</li>
    <li><strong>SKU:</strong> {{ $line?->sku ?: '-' }}</li>
    <li><strong>Product:</strong> {{ $product?->title ?: '-' }}</li>
    <li><strong>Quantity received:</strong> {{ $receipt->quantity_received }}</li>
    <li><strong>Staged at:</strong> {{ $receipt->created_at?->format('d/m/Y H:i') }}</li>
</ul>

<p>Please review the Supplier Orders tab and push the received stock to Shopify if the receipt is correct.</p>
