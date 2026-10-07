<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\DropdownOption;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopYourVibeTagService
{
    private const FIELD_HEADERS = [
        'materials_and_dimensions' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'color_string' => HeaderStore::COLOR_METAFIELD,
        'jewelry_material' => HeaderStore::JEWELRY_MATERIAL,
        'bead_colour_finish' => HeaderStore::BEAD_COLOUR_FINISH,
    ];

    public function __construct(
        private readonly ProductShopifyUpdater $productUpdater,
    ) {}

    public function productForGid(string $productGid): Product
    {
        $product = Product::query()
            ->where('shopify_id', $productGid)
            ->latest('id')
            ->first();

        if (! $product) {
            throw new RuntimeException('This product is not available locally. Refresh from Shopify and retry.');
        }

        return $product;
    }

    /**
     * @return array{materials_and_dimensions:string,color_string:array<int,string>,jewelry_material:array<int,string>,bead_colour_finish:string}
     */
    public function stateForProduct(Product $product): array
    {
        $row = $this->primaryRow($product);

        return [
            'materials_and_dimensions' => trim((string) ($row?->get(HeaderStore::MATERIALS_AND_DIMENSIONS, '') ?? '')),
            'color_string' => $this->splitValues((string) ($row?->get(HeaderStore::COLOR_METAFIELD, $product->color_string ?? '') ?? '')),
            'jewelry_material' => $this->splitValues((string) ($row?->get(HeaderStore::JEWELRY_MATERIAL, '') ?? '')),
            'bead_colour_finish' => trim((string) ($row?->get(HeaderStore::BEAD_COLOUR_FINISH, '') ?? '')),
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array<string, array<string, string>>
     */
    public function optionsForProduct(Product $product, ?array $state = null): array
    {
        $state ??= $this->stateForProduct($product);
        $tags = TagNormalizer::parseTokens((string) ($product->tags ?? ''));

        return [
            'materials_and_dimensions' => $this->options(
                HeaderStore::MATERIALS_AND_DIMENSIONS,
                selected: [$state['materials_and_dimensions'] ?? ''],
                tags: $tags,
            ),
            'color_string' => $this->options(
                HeaderStore::COLOR_METAFIELD,
                selected: is_array($state['color_string'] ?? null) ? $state['color_string'] : [],
                vendor: $product->vendor,
                productType: $product->type,
                tags: $tags,
            ),
            'jewelry_material' => $this->options(
                HeaderStore::JEWELRY_MATERIAL,
                selected: is_array($state['jewelry_material'] ?? null) ? $state['jewelry_material'] : [],
                tags: $tags,
            ),
            'bead_colour_finish' => $this->options(
                HeaderStore::BEAD_COLOUR_FINISH,
                selected: [$state['bead_colour_finish'] ?? ''],
                tags: $tags,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{product:Product,state:array<string,mixed>,options:array<string,array<string,string>>}
     */
    public function save(string $productGid, array $state, ?int $userId = null): array
    {
        $product = $this->productForGid($productGid);
        $row = $this->primaryRow($product);
        if (! $row) {
            throw new RuntimeException('The latest Shopify product data is unavailable. Refresh the product import and retry.');
        }

        $normalized = $this->normalizeState($state);
        $updates = [
            HeaderStore::MATERIALS_AND_DIMENSIONS => $normalized['materials_and_dimensions'],
            HeaderStore::COLOR_METAFIELD => $normalized['color_string'],
            HeaderStore::JEWELRY_MATERIAL => $normalized['jewelry_material'],
            HeaderStore::BEAD_COLOUR_FINISH => $normalized['bead_colour_finish'],
        ];
        $before = $this->stateForProduct($product);
        $this->guardAgainstUnsupportedClears($before, $normalized);

        $this->productUpdater->syncShopYourVibeTagFields($product, $updates);

        DB::transaction(function () use ($product, $row, $updates, $normalized, $before, $userId): void {
            foreach ($updates as $header => $value) {
                $row->set($header, $value);
            }
            $row->save();

            Product::withoutEvents(fn () => $product->forceFill([
                'color_string' => $normalized['color_string'] !== '' ? $normalized['color_string'] : null,
            ])->save());

            $this->mirrorDrafts($product, $normalized);
            $this->logChanges($product, $before, $normalized, $userId);
        });

        app(Normalizer::class)->recalculateErrorsForProduct($product->fresh());
        $this->syncProcurementRow($product);

        $fresh = $product->fresh();
        $state = $this->stateForProduct($fresh);

        return [
            'product' => $fresh,
            'state' => $state,
            'options' => $this->optionsForProduct($fresh, $state),
        ];
    }

    private function syncProcurementRow(Product $product): void
    {
        try {
            $variantIds = $product->variants()->pluck('id')->all();
            if ($variantIds !== []) {
                app(ProcurementSheetSyncService::class)->publishOperational($variantIds);
            }
        } catch (\Throwable $exception) {
            Log::warning('Procurement Sheet tag write-back failed after confirmed Shopify update.', [
                'product_id' => $product->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function primaryRow(Product $product): ?ShopifyRow
    {
        return ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $state
     * @return array{materials_and_dimensions:string,color_string:string,jewelry_material:string,bead_colour_finish:string}
     */
    private function normalizeState(array $state): array
    {
        return [
            'materials_and_dimensions' => trim((string) ($state['materials_and_dimensions'] ?? '')),
            'color_string' => $this->joinValues($state['color_string'] ?? []),
            'jewelry_material' => $this->joinValues($state['jewelry_material'] ?? []),
            'bead_colour_finish' => trim((string) ($state['bead_colour_finish'] ?? '')),
        ];
    }

    /**
     * @param array<int, string> $selected
     * @param array<int, string> $tags
     * @return array<string, string>
     */
    private function options(
        string $header,
        array $selected = [],
        ?string $vendor = null,
        ?string $productType = null,
        array $tags = [],
    ): array {
        $options = DropdownOption::optionsForHeader($header, $vendor, $productType, $tags)
            ->merge(collect($selected))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->sort()
            ->values();

        return $options->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }

    /**
     * @return array<int, string>
     */
    private function splitValues(string $value): array
    {
        $parts = preg_split('/[;,]+/', $value) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->unique(fn (string $part): string => mb_strtolower($part))
            ->values()
            ->all();
    }

    private function joinValues(mixed $values): string
    {
        if (! is_array($values)) {
            $values = $this->splitValues((string) $values);
        }

        return collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->implode('; ');
    }

    /**
     * @param array{materials_and_dimensions:string,color_string:string,jewelry_material:string,bead_colour_finish:string} $values
     */
    private function mirrorDrafts(Product $product, array $values): void
    {
        NewProductDraft::query()
            ->where(function ($query) use ($product): void {
                $shopifyId = trim((string) ($product->shopify_id ?? ''));
                $handle = trim((string) ($product->handle ?? ''));

                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
            })
            ->get()
            ->each(function (NewProductDraft $draft) use ($values): void {
                $warnings = collect($draft->shopify_sync_warnings ?? [])
                    ->reject(fn (mixed $warning): bool => in_array(data_get($warning, 'field'), array_keys(self::FIELD_HEADERS), true))
                    ->values()
                    ->all();

                NewProductDraft::withoutEvents(fn () => $draft->forceFill([
                    'materials_and_dimensions' => $values['materials_and_dimensions'] !== '' ? $values['materials_and_dimensions'] : null,
                    'color_string' => $values['color_string'] !== '' ? $values['color_string'] : null,
                    'jewelry_material' => $values['jewelry_material'] !== '' ? $values['jewelry_material'] : null,
                    'bead_colour_finish' => $values['bead_colour_finish'] !== '' ? $values['bead_colour_finish'] : null,
                    'shopify_sync_warnings' => $warnings !== [] ? $warnings : null,
                ])->save());
            });
    }

    /**
     * @param array<string, mixed> $before
     * @param array{materials_and_dimensions:string,color_string:string,jewelry_material:string,bead_colour_finish:string} $after
     */
    private function logChanges(Product $product, array $before, array $after, ?int $userId): void
    {
        $beforeComparable = [
            'materials_and_dimensions' => trim((string) ($before['materials_and_dimensions'] ?? '')),
            'color_string' => $this->joinValues($before['color_string'] ?? []),
            'jewelry_material' => $this->joinValues($before['jewelry_material'] ?? []),
            'bead_colour_finish' => trim((string) ($before['bead_colour_finish'] ?? '')),
        ];

        foreach ($after as $field => $newValue) {
            $oldValue = $beforeComparable[$field] ?? '';
            if ($oldValue === $newValue) {
                continue;
            }

            ChangeLog::create([
                'import_id' => $product->import_id,
                'product_id' => $product->id,
                'changed_by' => $userId,
                'source' => 'shop_your_vibe_tags',
                'model_type' => Product::class,
                'model_id' => $product->id,
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array{materials_and_dimensions:string,color_string:string,jewelry_material:string,bead_colour_finish:string} $after
     */
    private function guardAgainstUnsupportedClears(array $before, array $after): void
    {
        $beforeComparable = [
            'materials_and_dimensions' => trim((string) ($before['materials_and_dimensions'] ?? '')),
            'color_string' => $this->joinValues($before['color_string'] ?? []),
            'jewelry_material' => $this->joinValues($before['jewelry_material'] ?? []),
            'bead_colour_finish' => trim((string) ($before['bead_colour_finish'] ?? '')),
        ];

        $labels = [
            'materials_and_dimensions' => 'Material & Dimensions',
            'color_string' => 'Colour',
            'jewelry_material' => 'Jewelry Material',
            'bead_colour_finish' => 'Material Colour Finish',
        ];

        foreach ($after as $field => $newValue) {
            if (($beforeComparable[$field] ?? '') !== '' && $newValue === '') {
                throw new RuntimeException($labels[$field].' cannot be cleared from Shop Your Vibe yet. Choose a replacement value in this popup, or edit the product directly.');
            }
        }
    }
}
