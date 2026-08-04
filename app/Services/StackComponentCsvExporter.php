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
        $filename = 'product_draft_stacks_and_components_' . now()->format('Y-m-d_His') . '.csv';
        $exportedAt = now()->format('Y-m-d H:i:s T');

        return response()->streamDownload(function () use ($exportedAt): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new \RuntimeException('Unable to open the stacks and components CSV stream.');
            }

            fputcsv($handle, $this->headers());

            NewProductDraft::query()
                ->whereNotNull('bundle_product_ids')
                ->orderBy('id')
                ->chunkById(250, function (Collection $drafts) use ($handle, $exportedAt): void {
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
                        foreach ($this->componentIds($draft) as $position => $componentId) {
                            $component = $components->get($componentId);
                            fputcsv($handle, $this->row(
                                $draft,
                                $component instanceof Product ? $component : null,
                                $componentId,
                                $position + 1,
                                $exportedAt,
                            ));
                        }
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
    public function headers(): array
    {
        return [
            'exported_at',
            'stack_draft_id',
            'stack_shopify_product_id',
            'stack_handle',
            'stack_sku',
            'stack_title',
            'stack_vendor',
            'stack_status',
            'component_position',
            'component_product_id',
            'component_shopify_product_id',
            'component_handle',
            'component_skus',
            'component_title',
            'component_vendor',
            'component_status',
            'component_variant_count',
            'data_quality_note',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function componentIds(NewProductDraft $draft): array
    {
        return collect((array) $draft->bundle_product_ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function row(
        NewProductDraft $draft,
        ?Product $component,
        int $componentId,
        int $position,
        string $exportedAt,
    ): array {
        $variants = $component?->variants ?? collect();
        $componentSkus = $variants
            ->map(fn (Variant $variant): string => trim((string) $variant->sku))
            ->filter()
            ->unique()
            ->implode(' | ');

        $qualityNote = match (true) {
            $component === null => 'Linked component product is missing from the local Products catalogue.',
            $variants->isEmpty() => 'Linked component product has no variants.',
            $componentSkus === '' => 'Linked component variants have no SKU.',
            $variants->count() > 1 => 'Linked component has multiple variants; all component SKUs are listed.',
            default => '',
        };

        return [
            $exportedAt,
            (string) $draft->id,
            trim((string) $draft->shopify_id),
            trim((string) $draft->handle),
            trim((string) $draft->sku),
            trim((string) $draft->title),
            trim((string) $draft->vendor),
            trim((string) $draft->status),
            (string) $position,
            (string) $componentId,
            trim((string) $component?->shopify_id),
            trim((string) $component?->handle),
            $componentSkus,
            trim((string) $component?->title),
            trim((string) $component?->vendor),
            trim((string) $component?->status),
            (string) $variants->count(),
            $qualityNote,
        ];
    }
}
