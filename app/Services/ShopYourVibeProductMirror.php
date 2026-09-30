<?php

namespace App\Services;

use App\Models\DropdownOption;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use Illuminate\Support\Facades\DB;

class ShopYourVibeProductMirror
{
    private const FIELD_HEADERS = [
        'materials_and_dimensions' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'color_string' => HeaderStore::COLOR_METAFIELD,
        'jewelry_material' => HeaderStore::JEWELRY_MATERIAL,
        'bead_colour_finish' => HeaderStore::BEAD_COLOUR_FINISH,
    ];

    /**
     * @param array<string, mixed> $state
     */
    public function mirrorFromDraftState(array $state): void
    {
        $products = [];
        foreach (($state['collections'] ?? []) as $collection) {
            foreach (($collection['products'] ?? []) as $product) {
                if (is_array($product) && filled($product['id'] ?? null)) {
                    $gid = (string) $product['id'];
                    $products[$gid] = isset($products[$gid])
                        ? $this->mergeProductCards($products[$gid], $product)
                        : $product;
                }
            }
        }

        foreach ($products as $product) {
            $this->mirrorProductCard($product);
        }
    }

    /**
     * @param array<string, mixed> $card
     */
    private function mirrorProductCard(array $card): void
    {
        $gid = trim((string) ($card['id'] ?? ''));
        if ($gid === '') {
            return;
        }

        $products = Product::query()->where('shopify_id', $gid)->orderByDesc('id')->get();
        if ($products->isEmpty()) {
            return;
        }

        $values = $this->normalizedMetafieldValues($card['shopify_metafields'] ?? []);
        if ($values === [] && ! isset($card['tags'], $card['title'], $card['status'], $card['vendor'], $card['type'])) {
            return;
        }

        $products->each(function (Product $product) use ($card, $values): void {
            $this->mirrorLocalProduct($product, $card, $values);
        });
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeProductCards(array $existing, array $incoming): array
    {
        $merged = array_replace($existing, $incoming);

        $existingMetafields = is_array($existing['shopify_metafields'] ?? null) ? $existing['shopify_metafields'] : [];
        $incomingMetafields = is_array($incoming['shopify_metafields'] ?? null) ? $incoming['shopify_metafields'] : [];
        $existingHasMetafields = $this->hasMetafieldValue($existingMetafields);
        $incomingHasMetafields = $this->hasMetafieldValue($incomingMetafields);

        if ($existingHasMetafields && ! $incomingHasMetafields) {
            foreach (['tags', 'title', 'vendor', 'type', 'status'] as $key) {
                if (array_key_exists($key, $existing)) {
                    $merged[$key] = $existing[$key];
                }
            }
        }

        foreach ($existingMetafields as $header => $entry) {
            $incomingValue = data_get($incomingMetafields, $header.'.value');
            if (blank($incomingValue) && filled(data_get($entry, 'value'))) {
                $incomingMetafields[$header] = $entry;
            }
        }

        $merged['shopify_metafields'] = $incomingMetafields ?: $existingMetafields;

        return $merged;
    }

    /**
     * @param array<string, mixed> $metafields
     */
    private function hasMetafieldValue(array $metafields): bool
    {
        foreach ($metafields as $entry) {
            if (filled(data_get($entry, 'value'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $card
     * @param array<string, string> $values
     */
    private function mirrorLocalProduct(Product $product, array $card, array $values): void
    {
        DB::transaction(function () use ($product, $card, $values): void {
            $row = $this->primaryRow($product);

            if ($row && $values !== []) {
                foreach ($values as $header => $value) {
                    $row->set($header, $value);
                }
                $row->save();
            }

            $productUpdates = [];
            foreach (['title' => 'title', 'vendor' => 'vendor', 'type' => 'type', 'status' => 'status'] as $cardKey => $attribute) {
                if (array_key_exists($cardKey, $card) && is_scalar($card[$cardKey])) {
                    $productUpdates[$attribute] = trim((string) $card[$cardKey]) ?: null;
                }
            }
            if (array_key_exists('tags', $card) && is_array($card['tags'])) {
                $productUpdates['tags'] = implode(', ', array_values(array_filter(array_map(
                    static fn (mixed $tag): string => trim((string) $tag),
                    $card['tags'],
                ))));
            }
            if (isset($values[HeaderStore::COLOR_METAFIELD])) {
                $productUpdates['color_string'] = $values[HeaderStore::COLOR_METAFIELD] !== '' ? $values[HeaderStore::COLOR_METAFIELD] : null;
            }

            if ($productUpdates !== []) {
                Product::withoutEvents(fn () => $product->forceFill($productUpdates)->save());
            }

            $this->mirrorMetafieldRows($product, $card['shopify_metafields'] ?? []);
            $this->mirrorDrafts($product, $values);
            $this->upsertTrustedDropdownOptions($product->fresh(), $values);

            if ($row) {
                app(Normalizer::class)->recalculateErrorsForProduct($product->fresh(), null, $row->fresh());
            }
        });
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
     * @param mixed $metafields
     * @return array<string, string>
     */
    private function normalizedMetafieldValues(mixed $metafields): array
    {
        if (! is_array($metafields)) {
            return [];
        }

        $values = [];
        foreach (self::FIELD_HEADERS as $attribute => $header) {
            $entry = $metafields[$header] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $value = $this->normalizeMetafieldValue($entry);
            if ($attribute === 'color_string' || $attribute === 'jewelry_material') {
                $value = $this->joinDropdownValues($header, $value);
            }

            $values[$header] = $value;
        }

        return array_filter($values, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $metafield
     */
    private function normalizeMetafieldValue(array $metafield): string
    {
        $references = data_get($metafield, 'references.nodes');
        if (is_array($references) && $references !== []) {
            $labels = collect($references)
                ->map(fn (mixed $node): string => trim((string) (data_get($node, 'displayName') ?: data_get($node, 'name') ?: data_get($node, 'handle'))))
                ->filter()
                ->values();

            if ($labels->isNotEmpty()) {
                return $labels->implode('; ');
            }
        }

        $reference = data_get($metafield, 'reference');
        if (is_array($reference)) {
            $label = trim((string) (data_get($reference, 'displayName') ?: data_get($reference, 'name') ?: data_get($reference, 'handle')));
            if ($label !== '') {
                return $label;
            }
        }

        $value = trim((string) ($metafield['value'] ?? ''));
        if ($value === '') {
            return '';
        }

        $type = (string) ($metafield['type'] ?? '');
        if (str_starts_with($type, 'list.')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return collect($decoded)
                    ->map(fn (mixed $item): string => trim((string) $item))
                    ->filter()
                    ->implode('; ');
            }
        }

        if ($type === 'boolean') {
            return strtolower($value) === 'true' ? 'true' : 'false';
        }

        return $value;
    }

    private function joinDropdownValues(string $header, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $values = app(ShopifyTaxonomyValueNormalizer::class)->usesHandleValues($header)
            ? app(ShopifyTaxonomyValueNormalizer::class)->normalizeMany($header, $value)
            : preg_split('/[;,]+/', $value);

        return collect($values ?: [])
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter()
            ->unique(fn (string $part): string => mb_strtolower($part))
            ->values()
            ->implode('; ');
    }

    /**
     * @param mixed $metafields
     */
    private function mirrorMetafieldRows(Product $product, mixed $metafields): void
    {
        if (! is_array($metafields)) {
            return;
        }

        foreach (self::FIELD_HEADERS as $header) {
            $entry = $metafields[$header] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            [$namespace, $key] = $this->identifierFromHeader($header);
            ShopifyMetafield::updateOrCreate(
                [
                    'import_id' => $product->import_id,
                    'handle' => $product->handle,
                    'namespace' => $namespace,
                    'key' => $key,
                ],
                [
                    'type' => $entry['type'] ?? null,
                    'value' => $entry['value'] ?? null,
                ],
            );
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function mirrorDrafts(Product $product, array $values): void
    {
        if ($values === []) {
            return;
        }

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
                $updates = [];
                foreach (self::FIELD_HEADERS as $attribute => $header) {
                    if (isset($values[$header])) {
                        $updates[$attribute] = $values[$header] !== '' ? $values[$header] : null;
                    }
                }

                if ($updates !== []) {
                    NewProductDraft::withoutEvents(fn () => $draft->forceFill($updates)->save());
                }
            });
    }

    /**
     * @param array<string, string> $values
     */
    private function upsertTrustedDropdownOptions(Product $product, array $values): void
    {
        $context = $this->collectionContextForTags((string) ($product->tags ?? ''));
        if (($context['tag_primary'] ?? null) === null) {
            return;
        }

        foreach ($values as $header => $rawValue) {
            foreach ($this->parseDropdownValues($header, $rawValue) as $value) {
                $canonical = DropdownOption::canonicalValue($header, $value);
                $existing = DropdownOption::query()
                    ->where('header', $header)
                    ->where('collection_tag_primary', $context['tag_primary'])
                    ->when(
                        $context['tag_secondary'] ?? null,
                        fn ($query, string $secondary) => $query->where('collection_tag_secondary', $secondary),
                        fn ($query) => $query->whereNull('collection_tag_secondary'),
                    )
                    ->get()
                    ->first(fn (DropdownOption $option): bool => DropdownOption::canonicalValue($header, $option->value) === $canonical);

                if ($existing) {
                    $existing->forceFill(['active' => true])->save();
                    continue;
                }

                DropdownOption::create([
                    'header' => $header,
                    'value' => $value,
                    'collection_style' => $context['collection_style'],
                    'collection_tag_primary' => $context['tag_primary'],
                    'collection_tag_secondary' => $context['tag_secondary'],
                    'active' => true,
                    'sort_order' => 0,
                ]);
            }
        }
    }

    /**
     * @return array{collection_style:?string,tag_primary:?string,tag_secondary:?string}
     */
    private function collectionContextForTags(string $tags): array
    {
        $tokens = TagNormalizer::parseTokens($tags);
        $tokenSet = array_map('strtolower', $tokens);
        foreach (app(DropdownCollectionCatalog::class)->contexts() as $context) {
            $primary = strtolower((string) ($context['tag_primary'] ?? ''));
            $secondary = strtolower((string) ($context['tag_secondary'] ?? ''));

            if ($primary === '' || ! in_array($primary, $tokenSet, true)) {
                continue;
            }
            if ($secondary !== '' && ! in_array($secondary, $tokenSet, true)) {
                continue;
            }

            return [
                'collection_style' => $context['collection_style'],
                'tag_primary' => $context['tag_primary'],
                'tag_secondary' => $context['tag_secondary'],
            ];
        }

        return ['collection_style' => null, 'tag_primary' => null, 'tag_secondary' => null];
    }

    /**
     * @return array<int, string>
     */
    private function parseDropdownValues(string $header, string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        if ($header === HeaderStore::MATERIALS_AND_DIMENSIONS) {
            return [$raw];
        }

        if (app(ShopifyTaxonomyValueNormalizer::class)->usesHandleValues($header)) {
            return app(ShopifyTaxonomyValueNormalizer::class)->normalizeMany($header, $raw);
        }

        $parts = preg_split('/[;,]+/', str_replace(',', ';', $raw)) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->unique(fn (string $part): string => mb_strtolower($part))
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function identifierFromHeader(string $header): array
    {
        preg_match('/\(product\.metafields\.([^.]+)\.([^)]+)\)/', $header, $matches);

        return [$matches[1] ?? '', $matches[2] ?? ''];
    }
}
