<?php

namespace Tests\Support;

use App\Contracts\ShopifyGraphqlGateway;
use RuntimeException;

class VibeShopifyFake implements ShopifyGraphqlGateway
{
    public array $calls = [];

    public array $references = ['gid://shopify/Metaobject/12', 'gid://shopify/Metaobject/11'];

    public array $cards = [];

    public array $collections = [];

    public ?string $fail = null;

    public bool $jobDone = true;

    public function __construct()
    {
        foreach ([1 => 'necklaces', 2 => 'pearl', 3 => 'pastels', 4 => 'empty'] as $id => $handle) {
            $this->collections['gid://shopify/Collection/'.$id] = [
                'id' => 'gid://shopify/Collection/'.$id, 'title' => ucfirst($handle), 'handle' => $handle,
                'image' => ['url' => 'https://cdn.shopify.com/collection.jpg'], 'updatedAt' => '2026-09-08T12:00:00Z',
                'sortOrder' => 'MANUAL', 'ruleSet' => null,
                'products' => ['nodes' => array_map($this->product(...), [101, 102, 103]), 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ];
        }
        foreach ([11 => 'pearl', 12 => 'pastels'] as $id => $handle) {
            $this->cards['gid://shopify/Metaobject/'.$id] = ['id' => 'gid://shopify/Metaobject/'.$id,
                'type' => 'shop_your_vibe_card_preview', 'handle' => $handle, 'fields' => [
                    ['key' => 'name', 'value' => ucfirst($handle), 'reference' => null],
                    ['key' => 'image', 'value' => 'gid://shopify/MediaImage/1', 'reference' => ['image' => ['url' => 'https://cdn.shopify.com/image.jpg']]],
                    ['key' => 'link', 'value' => 'https://leighavenue.co.za/collections/'.$handle, 'reference' => null],
                ]];
        }
    }

    public function product(int $id): array
    {
        return ['id' => 'gid://shopify/Product/'.$id, 'title' => 'Product '.$id,
            'featuredImage' => ['url' => 'https://cdn.shopify.com/product.jpg'], 'variants' => ['nodes' => [['sku' => 'SKU-'.$id]]]];
    }

    public function graphql(string $query, array $variables = []): array
    {
        $this->calls[] = [$query, $variables];
        if (preg_match('/\bshop_your_vibe\b/', $query.json_encode($variables))) {
            throw new RuntimeException('The live metafield must never be accessed.');
        }
        if ($this->fail && str_contains($query, $this->fail)) {
            throw new RuntimeException('Simulated Shopify failure: '.$this->fail);
        }
        if (str_contains($query, 'query VibeDefinition')) {
            return ['metaobjectDefinitionByType' => ['type' => 'shop_your_vibe_card_preview', 'fieldDefinitions' => [
                ['key' => 'name', 'type' => ['name' => 'single_line_text_field']],
                ['key' => 'image', 'type' => ['name' => 'file_reference']],
                ['key' => 'link', 'type' => ['name' => 'url']],
            ]]];
        }
        if (str_contains($query, 'query VibeParents')) {
            return ['collections' => ['nodes' => array_map(fn ($collection) => $collection + ['metafield' => $this->metafield($collection['id'])], array_values($this->collections)),
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];
        }
        if (str_contains($query, 'query VibeParent(')) {
            return ['collection' => $this->collections[$variables['id']] + ['metafield' => $this->metafield($variables['id'])]];
        }
        if (str_contains($query, 'query VibeCards')) {
            return ['nodes' => array_map(fn ($id) => $this->cards[$id] ?? null, $variables['ids'])];
        }
        if (str_contains($query, 'query VibeProducts')) {
            return ['collection' => $this->collections[$variables['id']]];
        }
        if (str_contains($query, 'query VibeLinkedCollection')) {
            return ['collections' => ['nodes' => array_values($this->collections)]];
        }
        if (str_contains($query, 'query VibeJob')) {
            return ['job' => ['id' => $variables['id'], 'done' => $this->jobDone]];
        }
        if (str_contains($query, 'mutation VibeCreate') || str_contains($query, 'mutation VibeUpdate')) {
            $create = str_contains($query, 'mutation VibeCreate');
            $handle = $variables['handle']['handle'] ?? null;
            $existing = $create ? array_search($handle, array_column($this->cards, 'handle', 'id'), true) : null;
            $id = $create ? ($existing ?: 'gid://shopify/Metaobject/'.(count($this->cards) + 100)) : $variables['id'];
            $this->cards[$id] = ['id' => $id, 'type' => 'shop_your_vibe_card_preview', 'handle' => $handle ?? $this->cards[$id]['handle'],
                'fields' => array_map(fn ($field) => $field + ['reference' => null], $variables['input']['fields'])];

            return [$create ? 'metaobjectUpsert' : 'metaobjectUpdate' => ['metaobject' => ['id' => $id], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation VibeReferences')) {
            $input = $variables['metafields'][0];
            if ($input['key'] !== 'shop_your_vibe_preview' || $input['namespace'] !== 'custom') {
                throw new RuntimeException('Unexpected metafield write.');
            }
            $this->references = json_decode($input['value'], true);

            return ['metafieldsSet' => ['metafields' => [['value' => $input['value']]], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation VibeManual')) {
            $id = $variables['input']['id'];
            $this->collections[$id]['sortOrder'] = 'MANUAL';

            return ['collectionUpdate' => ['collection' => ['id' => $id, 'sortOrder' => 'MANUAL'], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation VibeAddProducts')) {
            foreach ($variables['ids'] as $id) {
                $this->collections[$variables['id']]['products']['nodes'][] = $this->product((int) basename($id));
            }

            return ['collectionAddProducts' => ['collection' => ['id' => $variables['id']], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation VibeRemoveProducts')) {
            $this->collections[$variables['id']]['products']['nodes'] = array_values(array_filter(
                $this->collections[$variables['id']]['products']['nodes'], fn ($product) => ! in_array($product['id'], $variables['ids'], true)));

            return ['collectionRemoveProducts' => ['job' => ['id' => 'gid://shopify/Job/remove'], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation VibeReorderProducts')) {
            $products = $this->collections[$variables['id']]['products']['nodes'];
            foreach ($variables['moves'] as $move) {
                $index = array_search($move['id'], array_column($products, 'id'), true);
                $product = $products[$index];
                array_splice($products, $index, 1);
                array_splice($products, (int) $move['newPosition'], 0, [$product]);
            }
            $this->collections[$variables['id']]['products']['nodes'] = $products;

            return ['collectionReorderProducts' => ['job' => ['id' => 'gid://shopify/Job/order'], 'userErrors' => []]];
        }

        throw new RuntimeException('Unexpected query: '.$query);
    }

    public function mutations(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => str_starts_with($call[0], 'mutation')));
    }

    private function metafield(string $gid): ?array
    {
        return $gid === 'gid://shopify/Collection/1' ? ['type' => 'list.metaobject_reference', 'value' => json_encode($this->references), 'compareDigest' => 'digest'] : null;
    }
}
