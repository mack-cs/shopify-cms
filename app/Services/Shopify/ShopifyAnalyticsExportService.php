<?php

namespace App\Services\Shopify;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\ProductInventorySnapshot;
use App\Models\SaleProductUpdate;
use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifyOrderTransaction;
use App\Models\SkuDailyDemand;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShopifyAnalyticsExportService
{
    public function mlProductsCsv(): StreamedResponse
    {
        return $this->csvResponse(
            'shopify_products_current.csv',
            [
                'Handle',
                'Variant SKU',
                'Variant Grams',
                'Variant Inventory Tracker',
                'Variant Inventory Qty',
                'Variant Inventory Policy',
                'Variant Price',
                'Variant Requires Shipping',
                'Variant Taxable',
                'Variant Barcode',
                'Status',
            ],
            function ($handle): void {
                Variant::query()
                    ->with('product')
                    ->active()
                    ->orderBy('id')
                    ->chunkById(1000, function ($variants) use ($handle): void {
                        foreach ($variants as $variant) {
                            if (! $variant->product) {
                                continue;
                            }

                            fputcsv($handle, [
                                $variant->product->handle,
                                $variant->sku,
                                $this->weightInGrams($variant->weight, $variant->weight_unit),
                                $variant->inventory_tracked === true ? 'shopify' : '',
                                $variant->inventory_tracked === true
                                    ? ($variant->current_inventory_quantity ?? $variant->inventory_qty)
                                    : null,
                                $variant->inventory_policy,
                                $variant->price,
                                $variant->requires_shipping ? 'TRUE' : 'FALSE',
                                $variant->taxable ? 'TRUE' : 'FALSE',
                                $variant->barcode,
                                $variant->product->status,
                            ]);
                        }
                    });
            },
        );
    }

    public function mlOrderLinesCsv(string $from, string $to): StreamedResponse
    {
        [$start, $end] = $this->utcWindow($from, $to);

        return $this->csvResponse(
            "shopify_order_lines_{$from}_to_{$to}.csv",
            [
                'Name',
                'Id',
                'Created at',
                'Processed at',
                'Financial Status',
                'Fulfillment Status',
                'Cancelled at',
                'Refunded Amount',
                'Source',
                'Payment Gateways',
                'Lineitem id',
                'Lineitem quantity',
                'Lineitem name',
                'Lineitem sku',
                'Lineitem price',
                'Lineitem discount',
                'Lineitem discounted total',
                'Vendor',
                'Product id',
                'Variant id',
                'Product handle',
                'Currency',
            ],
            function ($handle) use ($start, $end): void {
                ShopifyOrderItem::query()
                    ->with('order')
                    ->whereHas('order', fn ($query) => $query
                        ->where('created_at_shopify', '>=', $start)
                        ->where('created_at_shopify', '<', $end))
                    ->orderBy('id')
                    ->chunkById(1000, function ($items) use ($handle): void {
                        foreach ($items as $item) {
                            $order = $item->order;
                            if (! $order) {
                                continue;
                            }

                            fputcsv($handle, [
                                $order->name,
                                $order->shopify_order_id,
                                $order->created_at_shopify?->toIso8601String(),
                                $order->processed_at_shopify?->toIso8601String(),
                                strtolower((string) $order->financial_status),
                                strtolower((string) $order->fulfillment_status),
                                $order->cancelled_at_shopify?->toIso8601String(),
                                $order->refunded_amount,
                                $order->source_name,
                                implode('|', (array) $order->payment_gateway_names),
                                $item->shopify_line_item_id,
                                $item->quantity,
                                $item->title,
                                $item->sku,
                                $item->original_unit_price,
                                $item->total_discount,
                                $item->discounted_total,
                                $item->vendor,
                                $item->shopify_product_id,
                                $item->shopify_variant_id,
                                $item->product_handle,
                                $item->currency_code ?? $order->currency_code,
                            ]);
                        }
                    });
            },
        );
    }

    public function paymentPlatformCsv(string $from, string $to): StreamedResponse
    {
        [$start, $end] = $this->utcWindow($from, $to);
        $groups = [];

        ShopifyOrderTransaction::query()
            ->with('order:id,is_test')
            ->where(function ($query) use ($start, $end): void {
                $query->where('processed_at_shopify', '>=', $start)
                    ->where('processed_at_shopify', '<', $end)
                    ->orWhere(function ($created) use ($start, $end): void {
                        $created->whereNull('processed_at_shopify')
                            ->where('created_at_shopify', '>=', $start)
                            ->where('created_at_shopify', '<', $end);
                    });
            })
            ->orderBy('id')
            ->chunkById(1000, function ($transactions) use (&$groups): void {
                foreach ($transactions as $transaction) {
                    if ($transaction->is_test || $transaction->order?->is_test) {
                        continue;
                    }

                    $gateway = trim((string) ($transaction->formatted_gateway ?: $transaction->gateway ?: 'Unknown'));
                    $currency = strtoupper(trim((string) ($transaction->currency_code ?: 'ZAR')));
                    $key = $gateway.'|'.$currency;
                    $groups[$key] ??= [
                        'gateway' => $gateway,
                        'currency' => $currency,
                        'gross_collected' => 0.0,
                        'refunds' => 0.0,
                        'successful_transaction_count' => 0,
                        'failed_transaction_count' => 0,
                        'orders' => [],
                    ];

                    $kind = strtoupper((string) $transaction->kind);
                    $status = strtoupper((string) $transaction->status);
                    $amount = (float) ($transaction->amount ?? 0);

                    if ($status === 'SUCCESS' && in_array($kind, ['SALE', 'CAPTURE'], true)) {
                        $groups[$key]['gross_collected'] += $amount;
                        $groups[$key]['successful_transaction_count']++;
                        $groups[$key]['orders'][$transaction->shopify_order_id] = true;
                    } elseif ($status === 'SUCCESS' && $kind === 'REFUND') {
                        $groups[$key]['refunds'] += $amount;
                        $groups[$key]['successful_transaction_count']++;
                    } elseif ($status !== 'SUCCESS') {
                        $groups[$key]['failed_transaction_count']++;
                    }
                }
            });

        ksort($groups);

        return $this->csvResponse(
            "payment_platform_performance_{$from}_to_{$to}.csv",
            [
                'Payment platform',
                'Currency',
                'Paid order count',
                'Successful transaction count',
                'Failed/pending transaction count',
                'Gross collected',
                'Refunded',
                'Net collected',
                'Average paid order value',
            ],
            function ($handle) use ($groups): void {
                foreach ($groups as $group) {
                    $orderCount = count($group['orders']);
                    $net = $group['gross_collected'] - $group['refunds'];
                    fputcsv($handle, [
                        $group['gateway'],
                        $group['currency'],
                        $orderCount,
                        $group['successful_transaction_count'],
                        $group['failed_transaction_count'],
                        number_format($group['gross_collected'], 2, '.', ''),
                        number_format($group['refunds'], 2, '.', ''),
                        number_format($net, 2, '.', ''),
                        number_format($orderCount > 0 ? $group['gross_collected'] / $orderCount : 0, 2, '.', ''),
                    ]);
                }
            },
        );
    }

    public function inventorySnapshotsCsv(string $from, string $to): StreamedResponse
    {
        return $this->csvResponse(
            "shopify_inventory_snapshots_{$from}_to_{$to}.csv",
            [
                'Business date', 'Captured at', 'Sync run id', 'Inventory item id',
                'Product id', 'Variant id', 'Location id', 'Location', 'SKU', 'Barcode',
                'Product', 'Variant', 'Tracked', 'Available', 'On hand', 'Committed',
                'Incoming', 'Reserved', 'Damaged', 'Quality control', 'Safety stock',
            ],
            function ($handle) use ($from, $to): void {
                ShopifyInventorySnapshot::query()
                    ->whereBetween('business_date', [$from, $to])
                    ->orderBy('id')
                    ->chunkById(1000, function ($snapshots) use ($handle): void {
                        foreach ($snapshots as $snapshot) {
                            fputcsv($handle, [
                                $snapshot->business_date?->toDateString(),
                                $snapshot->snapshot_completed_at?->toIso8601String(),
                                $snapshot->sync_run_id,
                                $snapshot->shopify_inventory_item_id,
                                $snapshot->shopify_product_id,
                                $snapshot->shopify_variant_id,
                                $snapshot->shopify_location_id,
                                $snapshot->location_name,
                                $snapshot->sku,
                                $snapshot->barcode,
                                $snapshot->product_title,
                                $snapshot->variant_title,
                                $snapshot->tracked ? 1 : 0,
                                $snapshot->available,
                                $snapshot->on_hand,
                                $snapshot->committed,
                                $snapshot->incoming,
                                $snapshot->reserved,
                                $snapshot->damaged,
                                $snapshot->quality_control,
                                $snapshot->safety_stock,
                            ]);
                        }
                    });
            },
        );
    }

    public function inventoryEventsCsv(string $from, string $to): StreamedResponse
    {
        $timezone = (string) config('shopify_sync.timezone', 'Africa/Johannesburg');

        return $this->csvResponse(
            "inventory_events_{$from}_to_{$to}.csv",
            [
                'Product id',
                'Product inventory snapshot id',
                'Previous product inventory snapshot id',
                'Observed by',
                'Product title',
                'Product handle',
                'Product shopify id',
                'Event type',
                'Occurred at',
                'Source',
                'From is sellable',
                'To is sellable',
                'From is out of stock',
                'To is out of stock',
                'From status',
                'To status',
                'From reason',
                'To reason',
                'Metadata',
            ],
            function ($handle) use ($from, $to, $timezone): void {
                ProductInventoryEvent::query()
                    ->whereDate('occurred_at', '>=', $from)
                    ->whereDate('occurred_at', '<=', $to)
                    ->orderBy('id')
                    ->chunkById(1000, function ($events) use ($handle, $timezone): void {
                        foreach ($events as $event) {
                            fputcsv($handle, [
                                $event->product_id,
                                $event->product_inventory_snapshot_id,
                                $event->previous_product_inventory_snapshot_id,
                                $event->observed_by,
                                $event->product_title,
                                $event->product_handle,
                                $event->product_shopify_id,
                                $event->event_type,
                                $event->occurred_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                                $event->source,
                                $event->from_is_sellable,
                                $event->to_is_sellable,
                                $event->from_is_out_of_stock,
                                $event->to_is_out_of_stock,
                                $event->from_status,
                                $event->to_status,
                                $event->from_reason,
                                $event->to_reason,
                                json_encode($event->metadata, JSON_UNESCAPED_SLASHES),
                            ]);
                        }
                    });
            },
        );
    }

    public function stackComponentsCsv(): StreamedResponse
    {
        return $this->csvResponse(
            'stack_components.csv',
            [
                'Stack SKU', 'Stack Name',
                'Bracelet 1', 'SKU 1',
                'Bracelet 2', 'SKU 2',
                'Bracelet 3', 'SKU 3',
                'Bracelet 4', 'SKU 4',
            ],
            function ($handle): void {
                NewProductDraft::query()
                    ->whereNotNull('bundle_product_ids')
                    ->orderBy('id')
                    ->chunkById(250, function ($drafts) use ($handle): void {
                        foreach ($drafts as $draft) {
                            $componentIds = collect((array) $draft->bundle_product_ids)
                                ->map(fn ($id): int => (int) $id)
                                ->filter(fn (int $id): bool => $id > 0)
                                ->take(4)
                                ->values();
                            if ($componentIds->isEmpty()) {
                                continue;
                            }

                            $components = Product::query()
                                ->with('variants')
                                ->whereIn('id', $componentIds)
                                ->get()
                                ->keyBy('id');
                            $stackProduct = $this->draftProduct($draft);
                            $stackSku = strtoupper(trim((string) ($draft->sku
                                ?: $stackProduct?->variants?->first()?->sku)));
                            if ($stackSku === '') {
                                continue;
                            }

                            $row = [$stackSku, $draft->title ?: $stackProduct?->title];
                            foreach (range(0, 3) as $position) {
                                $component = $components->get($componentIds->get($position));
                                $row[] = $component?->title;
                                $row[] = $component?->variants?->first()?->sku;
                            }
                            fputcsv($handle, $row);
                        }
                    });
            },
        );
    }

    public function saleInventoryCsv(string $from, string $to): StreamedResponse
    {
        $saleSkus = SaleProductUpdate::query()
            ->whereNotNull('pushed_at')
            ->whereDate('pushed_at', '<=', $to)
            ->pluck('sku')
            ->merge(
                Variant::query()
                    ->whereNotNull('compare_at_price')
                    ->whereColumn('compare_at_price', '>', 'price')
                    ->pluck('sku')
            )
            ->map(fn ($sku): string => strtoupper(trim((string) $sku)))
            ->filter()
            ->unique()
            ->values();

        $rows = [];
        foreach ($saleSkus as $sku) {
            $variant = Variant::query()->with('product')->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->first();
            $sale = SaleProductUpdate::query()
                ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
                ->whereNotNull('pushed_at')
                ->whereDate('pushed_at', '<=', $to)
                ->latest('pushed_at')
                ->first();
            $demand = SkuDailyDemand::query()
                ->where('sku', $sku)
                ->whereBetween('demand_date', [$from, $to])
                ->selectRaw('COALESCE(SUM(gross_units), 0) gross_units')
                ->selectRaw('COALESCE(SUM(cancelled_units), 0) cancelled_units')
                ->selectRaw('COALESCE(SUM(refunded_units), 0) refunded_units')
                ->selectRaw('COALESCE(SUM(net_units), 0) net_units')
                ->selectRaw('COALESCE(SUM(net_revenue), 0) net_revenue')
                ->first();
            $snapshot = ShopifyInventorySnapshot::query()
                ->where('sku', $sku)
                ->whereDate('business_date', '<=', $from)
                ->latest('business_date')
                ->latest('snapshot_completed_at')
                ->first();
            $openingAvailable = null;
            $openingDate = $snapshot?->business_date?->toDateString();
            $openingCapturedAt = $snapshot?->snapshot_completed_at?->toIso8601String();
            $openingSource = $snapshot ? 'shopify_inventory_snapshots' : null;
            $qualityNote = '';
            if ($snapshot) {
                $openingAvailable = ShopifyInventorySnapshot::query()
                    ->where('sync_run_id', $snapshot->sync_run_id)
                    ->where('sku', $sku)
                    ->sum('available');
            } elseif ($variant?->product_id) {
                $legacySnapshot = ProductInventorySnapshot::query()
                    ->where('product_id', $variant->product_id)
                    ->whereDate('checked_date', '<=', $from)
                    ->latest('checked_at')
                    ->first();
                $legacyVariant = collect((array) $legacySnapshot?->variant_summary)
                    ->first(fn (mixed $row): bool => is_array($row)
                        && strtoupper(trim((string) ($row['sku'] ?? ''))) === $sku);

                if (is_array($legacyVariant) && is_numeric($legacyVariant['quantity'] ?? null)) {
                    $openingAvailable = (int) $legacyVariant['quantity'];
                    $openingDate = $legacySnapshot?->checked_date?->toDateString();
                    $openingCapturedAt = $legacySnapshot?->checked_at?->toIso8601String();
                    $openingSource = 'product_inventory_snapshots.variant_summary';
                    $qualityNote = 'Opening quantity came from the earlier local variant snapshot system';
                }
            }

            $rows[] = [
                $sku,
                $variant?->product?->title ?? $snapshot?->product_title,
                $sale?->pushed_at?->toIso8601String(),
                $sale?->sale_price ?? $variant?->price,
                $sale?->compare_at_price ?? $variant?->compare_at_price,
                $openingDate,
                $openingCapturedAt,
                $openingSource,
                $openingAvailable,
                (int) ($demand?->gross_units ?? 0),
                (int) ($demand?->cancelled_units ?? 0),
                (int) ($demand?->refunded_units ?? 0),
                (int) ($demand?->net_units ?? 0),
                $demand?->net_revenue ?? '0.00',
                $variant?->current_available_quantity ?? $variant?->inventory_qty,
                $openingAvailable === null ? null : $openingAvailable - (int) ($demand?->net_units ?? 0),
                $openingAvailable === null
                    ? 'No inventory snapshot existed on or before the requested start date'
                    : $qualityNote,
            ];
        }

        return $this->csvResponse(
            "sale_inventory_performance_{$from}_to_{$to}.csv",
            [
                'SKU',
                'Product',
                'Sale activated at',
                'Sale price',
                'Compare-at price',
                'Opening snapshot business date',
                'Opening snapshot captured at',
                'Opening snapshot source',
                'Opening available units',
                'Gross units sold',
                'Cancelled units',
                'Refunded units',
                'Net units sold',
                'Net product revenue',
                'Current available units',
                'Theoretical remaining from opening stock',
                'Data quality note',
            ],
            function ($handle) use ($rows): void {
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
            },
        );
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function utcWindow(string $from, string $to): array
    {
        $timezone = (string) config('shopify_sync.timezone', 'Africa/Johannesburg');

        return [
            Carbon::parse($from, $timezone)->startOfDay()->utc(),
            Carbon::parse($to, $timezone)->addDay()->startOfDay()->utc(),
        ];
    }

    private function draftProduct(NewProductDraft $draft): ?Product
    {
        $shopifyId = trim((string) $draft->shopify_id);
        if ($shopifyId !== '') {
            $product = Product::query()->with('variants')->where('shopify_id', $shopifyId)->first();
            if ($product) {
                return $product;
            }
        }

        $handle = trim((string) $draft->handle);

        return $handle === ''
            ? null
            : Product::query()->with('variants')->where('handle', $handle)->first();
    }

    private function weightInGrams(mixed $weight, mixed $unit): ?float
    {
        if (! is_numeric((string) $weight)) {
            return null;
        }

        $weight = (float) $weight;

        return match (strtolower(trim((string) $unit))) {
            'kg' => $weight * 1000,
            'lb', 'lbs' => $weight * 453.59237,
            'oz' => $weight * 28.349523125,
            default => $weight,
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  callable(resource):void  $writer
     */
    private function csvResponse(string $filename, array $headers, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writer): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }

            fputcsv($handle, $headers);
            $writer($handle);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
