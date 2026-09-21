<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StackComponentCsvExporter
{
    public function download(): StreamedResponse
    {
        $componentColumns = max(4, $this->largestComponentCount());
        $filename = 'stack_components_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($componentColumns): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new \RuntimeException('Unable to open the stacks and components CSV stream.');
            }

            // Excel uses the BOM to identify UTF-8 names such as Cafe with an accent correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->headers($componentColumns));

            NewProductDraft::query()
                ->whereNotNull('bundle_product_ids')
                ->orderBy('id')
                ->chunkById(250, function (Collection $drafts) use ($handle, $componentColumns): void {
                    $componentIds = $drafts
                        ->flatMap(fn (NewProductDraft $draft): array => $this->componentIds($draft))
                        ->unique()
                        ->values();

                    $components = Product::query()
                        ->with(['variants' => fn ($query) => $query->orderBy('id')])
                        ->whereIn('id', $componentIds->all())
                        ->get()
                        ->keyBy('id');

                    foreach ($drafts as $draft) {
                        $ids = $this->componentIds($draft);
                        if ($ids === []) {
                            continue;
                        }

                        fputcsv($handle, $this->row($draft, $ids, $components, $componentColumns));
                    }
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
    public function headers(int $componentColumns = 4): array
    {
        $headers = ['Stack SKU', 'Stack Name'];

        foreach (range(1, max(1, $componentColumns)) as $position) {
            $headers[] = "Bracelet {$position}";
            $headers[] = "SKU {$position}";
        }

        return $headers;
    }

    private function largestComponentCount(): int
    {
        return (int) NewProductDraft::query()
            ->whereNotNull('bundle_product_ids')
            ->get(['bundle_product_ids'])
            ->max(fn (NewProductDraft $draft): int => count($this->componentIds($draft)));
    }

    /**
     * @return array<int, int>
     */
    private function componentIds(NewProductDraft $draft): array
    {
        $quantities = collect((array) $draft->bundle_component_quantities)
            ->filter(fn ($row): bool => is_array($row) && (int) ($row['product_id'] ?? 0) > 0)
            ->mapWithKeys(fn (array $row): array => [
                (int) $row['product_id'] => max(1, (int) ($row['quantity'] ?? 1)),
            ]);

        return collect((array) $draft->bundle_product_ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->flatMap(fn (int $id): array => array_fill(0, (int) ($quantities[$id] ?? 1), $id))
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $componentIds
     * @param Collection<int, Product> $components
     * @return array<int, string>
     */
    private function row(
        NewProductDraft $draft,
        array $componentIds,
        Collection $components,
        int $componentColumns,
    ): array {
        $stackSku = trim((string) $draft->sku);
        $row = [
            $stackSku === '' ? '0' : $stackSku,
            trim((string) $draft->title),
        ];

        foreach (range(0, max(1, $componentColumns) - 1) as $position) {
            $component = $components->get($componentIds[$position] ?? 0);
            $row[] = $component instanceof Product ? trim((string) $component->title) : '';
            $row[] = $component instanceof Product ? $this->componentSku($component) : '';
        }

        return $row;
    }

    private function componentSku(Product $component): string
    {
        $variant = $component->variants
            ->first(fn (Variant $variant): bool => trim((string) $variant->sku) !== '')
            ?? $component->variants->first();

        return $variant instanceof Variant ? trim((string) $variant->sku) : '';
    }
}
