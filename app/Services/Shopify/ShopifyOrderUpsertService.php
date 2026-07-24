<?php

namespace App\Services\Shopify;

use App\Models\ShopifyDiscountApplication;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifyOrderTransaction;
use App\Models\ShopifyRefund;
use App\Models\ShopifyRefundLineItem;
use App\Models\ShopifySyncRun;
use Illuminate\Support\Carbon;

final class ShopifyOrderUpsertService
{
    public function upsertOrder(array $record, ShopifySyncRun $run): ShopifyOrder
    {
        $shopifyOrderId = trim((string) ($record['id'] ?? ''));
        if ($shopifyOrderId === '') {
            throw new \InvalidArgumentException('Order record is missing id.');
        }

        $incomingUpdatedAt = $this->parseDate($record['updatedAt'] ?? null);
        $existing = ShopifyOrder::query()->where('shopify_order_id', $shopifyOrderId)->first();

        if ($existing instanceof ShopifyOrder && $existing->updated_at_shopify !== null && $incomingUpdatedAt !== null && $existing->updated_at_shopify->gt($incomingUpdatedAt)) {
            $existing->forceFill(['last_seen_at' => now()])->save();

            return $existing;
        }

        $currency = $this->moneyCurrency($record['totalPriceSet'] ?? null)
            ?? $this->moneyCurrency($record['subtotalPriceSet'] ?? null)
            ?? $this->moneyCurrency($record['totalDiscountsSet'] ?? null);

        $payload = [
            'shopify_order_id' => $shopifyOrderId,
            'shopify_order_number' => $this->orderNumber($record['name'] ?? null),
            'name' => $this->nullIfBlank($record['name'] ?? null),
            'created_at_shopify' => $this->parseDate($record['createdAt'] ?? null),
            'updated_at_shopify' => $incomingUpdatedAt,
            'processed_at_shopify' => $this->parseDate($record['processedAt'] ?? null),
            'cancelled_at_shopify' => $this->parseDate($record['cancelledAt'] ?? null),
            'cancel_reason' => $this->nullIfBlank($record['cancelReason'] ?? null),
            'financial_status' => $this->nullIfBlank($record['displayFinancialStatus'] ?? null),
            'fulfillment_status' => $this->nullIfBlank($record['displayFulfillmentStatus'] ?? null),
            'currency_code' => $this->nullIfBlank($record['currencyCode'] ?? $currency),
            'subtotal_amount' => $this->moneyAmount($record['subtotalPriceSet'] ?? null),
            'total_amount' => $this->moneyAmount($record['totalPriceSet'] ?? null),
            'discount_amount' => $this->moneyAmount($record['totalDiscountsSet'] ?? null),
            'shipping_amount' => $this->moneyAmount($record['totalShippingPriceSet'] ?? null),
            'tax_amount' => $this->moneyAmount($record['totalTaxSet'] ?? null),
            'refunded_amount' => $this->moneyAmount($record['totalRefundedSet'] ?? null),
            'source_name' => $this->nullIfBlank($record['sourceName'] ?? null),
            'payment_gateway_names' => is_array($record['paymentGatewayNames'] ?? null)
                ? array_values(array_filter(array_map('strval', $record['paymentGatewayNames'])))
                : null,
            'is_test' => (bool) ($record['test'] ?? false),
            'customer_accepts_marketing' => array_key_exists('customerAcceptsMarketing', $record) ? (bool) $record['customerAcceptsMarketing'] : null,
            'billing_country' => $this->nullIfBlank(data_get($record, 'billingAddress.country')),
            'billing_province' => $this->nullIfBlank(data_get($record, 'billingAddress.province')),
            'billing_city' => $this->nullIfBlank(data_get($record, 'billingAddress.city')),
            'shipping_country' => $this->nullIfBlank(data_get($record, 'shippingAddress.country')),
            'shipping_province' => $this->nullIfBlank(data_get($record, 'shippingAddress.province')),
            'shipping_city' => $this->nullIfBlank(data_get($record, 'shippingAddress.city')),
            'tags' => is_array($record['tags'] ?? null) ? array_values($record['tags']) : null,
            'latest_sync_run_id' => $run->id,
            'first_seen_at' => $existing?->first_seen_at ?? now(),
            'last_seen_at' => now(),
        ];

        return ShopifyOrder::query()->updateOrCreate(
            ['shopify_order_id' => $shopifyOrderId],
            $payload,
        );
    }

    public function upsertOrderItem(array $record, ShopifySyncRun $run, ?ShopifyOrder $parentOrder = null): ?ShopifyOrderItem
    {
        $lineItemId = trim((string) ($record['id'] ?? ''));
        if ($lineItemId === '') {
            throw new \InvalidArgumentException('Line item record is missing id.');
        }

        $shopifyOrderId = trim((string) ($record['__parentId'] ?? $parentOrder?->shopify_order_id ?? data_get($record, 'order.id', '')));
        if ($shopifyOrderId === '') {
            throw new \InvalidArgumentException("Line item {$lineItemId} is missing parent order id.");
        }

        $parentOrder ??= ShopifyOrder::query()->where('shopify_order_id', $shopifyOrderId)->first();
        $incomingOrderUpdatedAt = $parentOrder?->updated_at_shopify ?? $this->parseDate($record['orderUpdatedAt'] ?? null);
        $existing = ShopifyOrderItem::query()->where('shopify_line_item_id', $lineItemId)->first();

        if ($existing instanceof ShopifyOrderItem && $existing->order_updated_at_shopify !== null && $incomingOrderUpdatedAt !== null && $existing->order_updated_at_shopify->gt($incomingOrderUpdatedAt)) {
            $existing->forceFill(['last_seen_at' => now()])->save();

            return $existing;
        }

        $sku = $this->normalizeSku($record['sku'] ?? data_get($record, 'variant.sku'));
        $payload = [
            'shopify_line_item_id' => $lineItemId,
            'shopify_order_id' => $shopifyOrderId,
            'shopify_order_db_id' => $parentOrder?->id,
            'shopify_product_id' => $this->nullIfBlank(data_get($record, 'product.id')),
            'shopify_variant_id' => $this->nullIfBlank(data_get($record, 'variant.id')),
            'sku' => $sku,
            'title' => $this->nullIfBlank($record['title'] ?? null),
            'quantity' => (int) ($record['quantity'] ?? 0),
            'vendor' => $this->nullIfBlank($record['vendor'] ?? null),
            'taxable' => array_key_exists('taxable', $record) ? (bool) $record['taxable'] : null,
            'requires_shipping' => array_key_exists('requiresShipping', $record) ? (bool) $record['requiresShipping'] : null,
            'original_unit_price' => $this->moneyAmount($record['originalUnitPriceSet'] ?? null),
            'discounted_total' => $this->moneyAmount($record['discountedTotalSet'] ?? null),
            'total_discount' => $this->moneyAmount($record['totalDiscountSet'] ?? null),
            'currency_code' => $this->moneyCurrency($record['discountedTotalSet'] ?? null)
                ?? $this->moneyCurrency($record['originalUnitPriceSet'] ?? null)
                ?? $parentOrder?->currency_code,
            'product_title' => $this->nullIfBlank(data_get($record, 'product.title')),
            'product_handle' => $this->nullIfBlank(data_get($record, 'product.handle')),
            'product_type' => $this->nullIfBlank(data_get($record, 'product.productType')),
            'product_vendor' => $this->nullIfBlank(data_get($record, 'product.vendor')),
            'product_status' => $this->nullIfBlank(data_get($record, 'product.status')),
            'variant_title' => $this->nullIfBlank(data_get($record, 'variant.title')),
            'variant_sku' => $this->nullIfBlank(data_get($record, 'variant.sku')),
            'barcode' => $this->nullIfBlank(data_get($record, 'variant.barcode')),
            'variant_price_at_export' => $this->decimalOrNull(data_get($record, 'variant.price')),
            'variant_inventory_quantity_at_export' => $this->intOrNull(data_get($record, 'variant.inventoryQuantity')),
            'order_created_at_shopify' => $parentOrder?->created_at_shopify,
            'order_updated_at_shopify' => $incomingOrderUpdatedAt,
            'latest_sync_run_id' => $run->id,
            'first_seen_at' => $existing?->first_seen_at ?? now(),
            'last_seen_at' => now(),
        ];

        return ShopifyOrderItem::query()->updateOrCreate(
            ['shopify_line_item_id' => $lineItemId],
            $payload,
        );
    }

    public function upsertRefund(array $record, ShopifySyncRun $run, ShopifyOrder $order): ?ShopifyRefund
    {
        $refundId = trim((string) ($record['id'] ?? ''));
        if ($refundId === '') {
            return null;
        }

        $existing = ShopifyRefund::query()->where('shopify_refund_id', $refundId)->first();

        return ShopifyRefund::query()->updateOrCreate(
            ['shopify_refund_id' => $refundId],
            [
                'shopify_refund_id' => $refundId,
                'shopify_order_id' => $order->shopify_order_id,
                'shopify_order_db_id' => $order->id,
                'order_name' => $order->name,
                'refund_created_at_shopify' => $this->parseDate($record['createdAt'] ?? null),
                'note' => $this->nullIfBlank($record['note'] ?? null),
                'refunded_amount' => $this->moneyAmount($record['totalRefundedSet'] ?? null),
                'currency_code' => $this->moneyCurrency($record['totalRefundedSet'] ?? null) ?? $order->currency_code,
                'latest_sync_run_id' => $run->id,
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function upsertTransaction(array $record, ShopifySyncRun $run, ShopifyOrder $order): ?ShopifyOrderTransaction
    {
        $transactionId = trim((string) ($record['id'] ?? ''));
        if ($transactionId === '') {
            return null;
        }

        $existing = ShopifyOrderTransaction::query()
            ->where('shopify_transaction_id', $transactionId)
            ->first();

        return ShopifyOrderTransaction::query()->updateOrCreate(
            ['shopify_transaction_id' => $transactionId],
            [
                'shopify_transaction_id' => $transactionId,
                'shopify_order_id' => $order->shopify_order_id,
                'shopify_order_db_id' => $order->id,
                'parent_transaction_id' => $this->nullIfBlank(data_get($record, 'parentTransaction.id')),
                'kind' => strtoupper(trim((string) ($record['kind'] ?? 'UNKNOWN'))),
                'status' => strtoupper(trim((string) ($record['status'] ?? 'UNKNOWN'))),
                'gateway' => $this->nullIfBlank($record['gateway'] ?? null),
                'formatted_gateway' => $this->nullIfBlank($record['formattedGateway'] ?? null),
                'amount' => $this->moneyAmount($record['amountSet'] ?? null),
                'currency_code' => $this->moneyCurrency($record['amountSet'] ?? null) ?? $order->currency_code,
                'created_at_shopify' => $this->parseDate($record['createdAt'] ?? null),
                'processed_at_shopify' => $this->parseDate($record['processedAt'] ?? null),
                'error_code' => $this->nullIfBlank($record['errorCode'] ?? null),
                'manual_payment_gateway' => array_key_exists('manualPaymentGateway', $record)
                    ? (bool) $record['manualPaymentGateway']
                    : null,
                'is_test' => (bool) ($record['test'] ?? false),
                'latest_sync_run_id' => $run->id,
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function upsertRefundLineItem(
        array $record,
        ShopifySyncRun $run,
        ?ShopifyRefund $refund = null,
        ?ShopifyOrder $order = null,
    ): ?ShopifyRefundLineItem {
        $refundLineItemId = trim((string) ($record['id'] ?? ''));
        $refundId = trim((string) ($record['__parentId'] ?? $refund?->shopify_refund_id ?? ''));
        $lineItemId = trim((string) data_get($record, 'lineItem.id', ''));

        if ($refundLineItemId === '' || $refundId === '' || $lineItemId === '') {
            return null;
        }

        $refund ??= ShopifyRefund::query()->where('shopify_refund_id', $refundId)->first();
        $order ??= $refund?->order;
        $orderItem = ShopifyOrderItem::query()
            ->where('shopify_line_item_id', $lineItemId)
            ->first();
        $existing = ShopifyRefundLineItem::query()
            ->where('shopify_refund_line_item_id', $refundLineItemId)
            ->first();

        return ShopifyRefundLineItem::query()->updateOrCreate(
            ['shopify_refund_line_item_id' => $refundLineItemId],
            [
                'shopify_refund_line_item_id' => $refundLineItemId,
                'shopify_refund_id' => $refundId,
                'shopify_refund_db_id' => $refund?->id,
                'shopify_order_id' => $order?->shopify_order_id ?? $orderItem?->shopify_order_id,
                'shopify_order_db_id' => $order?->id ?? $orderItem?->shopify_order_db_id,
                'shopify_line_item_id' => $lineItemId,
                'shopify_order_item_db_id' => $orderItem?->id,
                'quantity' => (int) ($record['quantity'] ?? 0),
                'subtotal_amount' => $this->moneyAmount($record['subtotalSet'] ?? null),
                'tax_amount' => $this->moneyAmount($record['totalTaxSet'] ?? null),
                'currency_code' => $this->moneyCurrency($record['subtotalSet'] ?? null)
                    ?? $order?->currency_code
                    ?? $orderItem?->currency_code,
                'restocked' => array_key_exists('restocked', $record) ? (bool) $record['restocked'] : null,
                'restock_type' => $this->nullIfBlank($record['restockType'] ?? null),
                'shopify_location_id' => $this->nullIfBlank(data_get($record, 'location.id')),
                'location_name' => $this->nullIfBlank(data_get($record, 'location.name')),
                'latest_sync_run_id' => $run->id,
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function upsertDiscount(array $record, ShopifySyncRun $run, ?ShopifyOrder $parentOrder = null): ?ShopifyDiscountApplication
    {
        $shopifyOrderId = trim((string) ($record['__parentId'] ?? $parentOrder?->shopify_order_id ?? ''));
        if ($shopifyOrderId === '') {
            return null;
        }

        $parentOrder ??= ShopifyOrder::query()->where('shopify_order_id', $shopifyOrderId)->first();

        $amount = $this->moneyAmount($record['value'] ?? null);
        $percentage = $this->decimalOrNull(data_get($record, 'value.percentage'));
        $currency = $this->moneyCurrency($record['value'] ?? null) ?? $parentOrder?->currency_code;
        $key = self::discountKey(
            $shopifyOrderId,
            $record['allocationMethod'] ?? null,
            $record['targetSelection'] ?? null,
            $record['targetType'] ?? null,
            $amount !== null ? 'amount' : ($percentage !== null ? 'percentage' : null),
            $amount,
            $percentage,
            $currency,
        );

        $existing = ShopifyDiscountApplication::query()->where('discount_key', $key)->first();

        return ShopifyDiscountApplication::query()->updateOrCreate(
            ['discount_key' => $key],
            [
                'discount_key' => $key,
                'shopify_order_id' => $shopifyOrderId,
                'shopify_order_db_id' => $parentOrder?->id,
                'allocation_method' => $this->nullIfBlank($record['allocationMethod'] ?? null),
                'target_selection' => $this->nullIfBlank($record['targetSelection'] ?? null),
                'target_type' => $this->nullIfBlank($record['targetType'] ?? null),
                'value_type' => $amount !== null ? 'amount' : ($percentage !== null ? 'percentage' : null),
                'discount_amount' => $amount,
                'discount_percentage' => $percentage,
                'currency_code' => $currency,
                'latest_sync_run_id' => $run->id,
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function relinkItemsAndChildren(): void
    {
        ShopifyOrderItem::query()
            ->whereNull('shopify_order_db_id')
            ->whereNotNull('shopify_order_id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    if (! $item instanceof ShopifyOrderItem) {
                        continue;
                    }

                    $order = ShopifyOrder::query()->where('shopify_order_id', $item->shopify_order_id)->first();
                    if ($order instanceof ShopifyOrder) {
                        $item->forceFill(['shopify_order_db_id' => $order->id])->save();
                    }
                }
            });

        foreach ([ShopifyRefund::class, ShopifyDiscountApplication::class, ShopifyOrderTransaction::class] as $modelClass) {
            $modelClass::query()
                ->whereNull('shopify_order_db_id')
                ->whereNotNull('shopify_order_id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $order = ShopifyOrder::query()->where('shopify_order_id', $row->shopify_order_id)->first();
                        if ($order instanceof ShopifyOrder) {
                            $row->forceFill(['shopify_order_db_id' => $order->id])->save();
                        }
                    }
                });
        }

        ShopifyRefundLineItem::query()
            ->whereNull('shopify_order_item_db_id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $item = ShopifyOrderItem::query()
                        ->where('shopify_line_item_id', $row->shopify_line_item_id)
                        ->first();
                    $refund = ShopifyRefund::query()
                        ->where('shopify_refund_id', $row->shopify_refund_id)
                        ->first();

                    $row->forceFill([
                        'shopify_order_item_db_id' => $item?->id,
                        'shopify_refund_db_id' => $refund?->id,
                        'shopify_order_db_id' => $refund?->shopify_order_db_id ?? $item?->shopify_order_db_id,
                        'shopify_order_id' => $refund?->shopify_order_id ?? $item?->shopify_order_id,
                    ])->save();
                }
            });
    }

    public static function discountKey(
        mixed $shopifyOrderId,
        mixed $allocationMethod,
        mixed $targetSelection,
        mixed $targetType,
        mixed $valueType,
        mixed $discountAmount,
        mixed $discountPercentage,
        mixed $currencyCode,
    ): string {
        return hash('sha256', implode('|', array_map(
            fn (mixed $value): string => trim((string) $value),
            [
                $shopifyOrderId,
                $allocationMethod,
                $targetSelection,
                $targetType,
                $valueType,
                $discountAmount,
                $discountPercentage,
                $currencyCode,
            ],
        )));
    }

    public function normalizeSku(mixed $sku): ?string
    {
        $sku = strtoupper(trim((string) ($sku ?? '')));

        return $sku === '' ? null : $sku;
    }

    private function orderNumber(mixed $name): ?string
    {
        $name = trim((string) ($name ?? ''));

        return $name === '' ? null : ltrim($name, '#');
    }

    private function moneyAmount(mixed $value): ?string
    {
        $amount = data_get($value, 'shopMoney.amount', data_get($value, 'amount'));

        return $this->decimalOrNull($amount);
    }

    private function moneyCurrency(mixed $value): ?string
    {
        return $this->nullIfBlank(data_get($value, 'shopMoney.currencyCode', data_get($value, 'currencyCode')));
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || ! is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric((string) $value) ? (int) $value : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : Carbon::parse($value);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
