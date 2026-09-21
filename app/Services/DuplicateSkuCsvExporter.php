<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DuplicateSkuCsvExporter
{
    public function __construct(
        private readonly DuplicateSkuAuditService $auditService,
    ) {
    }

    public function download(): StreamedResponse
    {
        $conflicts = $this->auditService->findConflicts();
        $filename = 'duplicate_sku_products_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($conflicts): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $this->headers());

            $productCountsBySku = collect($conflicts)
                ->mapWithKeys(fn (array $conflict): array => [
                    $conflict['sku'] => (int) $conflict['product_count'],
                ])
                ->all();

            $variantIds = collect($conflicts)
                ->flatMap(fn (array $conflict): array => collect($conflict['products'])
                    ->flatMap(fn (array $product): array => $product['variant_ids'])
                    ->all())
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $variantIds
                ->chunk(500)
                ->each(function ($ids) use ($handle, $productCountsBySku): void {
                    Variant::query()
                        ->with('product')
                        ->whereIn('id', $ids->all())
                        ->orderByRaw('UPPER(TRIM(sku))')
                        ->orderBy('product_id')
                        ->orderBy('id')
                        ->get()
                        ->each(function (Variant $variant) use ($handle, $productCountsBySku): void {
                            if (!$variant->product instanceof Product) {
                                return;
                            }

                            $normalizedSku = strtoupper(trim((string) $variant->sku));
                            fputcsv($handle, $this->row(
                                $variant,
                                $variant->product,
                                $normalizedSku,
                                (int) ($productCountsBySku[$normalizedSku] ?? 0),
                            ));
                        });
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return [
            'duplicate_sku',
            'products_sharing_sku',
            'product_id',
            'shopify_product_id',
            'product_handle',
            'product_title',
            'vendor',
            'product_status',
            'variant_id',
            'shopify_variant_id',
            'recorded_sku',
            'variant_options',
            'price',
            'inventory_tracked',
            'inventory_quantity',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function row(
        Variant $variant,
        Product $product,
        string $normalizedSku,
        int $productCount,
    ): array {
        return [
            $normalizedSku,
            (string) $productCount,
            (string) $product->id,
            trim((string) $product->shopify_id),
            trim((string) $product->handle),
            trim((string) $product->title),
            trim((string) $product->vendor),
            trim((string) $product->status),
            (string) $variant->id,
            trim((string) $variant->shopify_id),
            trim((string) $variant->sku),
            $this->variantOptions($variant),
            $variant->price === null ? '' : (string) $variant->price,
            match ($variant->inventory_tracked) {
                true => 'true',
                false => 'false',
                null => '',
            },
            $variant->inventory_qty === null ? '' : (string) ((int) $variant->inventory_qty),
        ];
    }

    private function variantOptions(Variant $variant): string
    {
        return collect([
            [$variant->option1_name, $variant->option1_value],
            [$variant->option2_name, $variant->option2_value],
            [$variant->option3_name, $variant->option3_value],
        ])
            ->filter(fn (array $option): bool => trim((string) $option[1]) !== '')
            ->map(function (array $option): string {
                $name = trim((string) $option[0]);
                $value = trim((string) $option[1]);

                return $name === '' ? $value : "{$name}: {$value}";
            })
            ->implode(' | ');
    }
}
