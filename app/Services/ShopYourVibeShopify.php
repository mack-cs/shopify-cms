<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyCollection;
use RuntimeException;

/** All remote access for this editor is scoped to the existing preview definition. */
class ShopYourVibeShopify
{
    public const TYPE = 'shop_your_vibe_card_preview';

    public const KEY = 'shop_your_vibe_preview';

    public function __construct(private readonly ShopifyGraphqlGateway $client) {}

    public function definition(): array
    {
        $data = $this->client->graphql(<<<'GQL'
query VibeDefinition { metaobjectDefinitionByType(type: "shop_your_vibe_card_preview") {
  type fieldDefinitions { key type { name } }
} }
GQL);
        $fields = collect(data_get($data, 'metaobjectDefinitionByType.fieldDefinitions', []))
            ->mapWithKeys(fn ($field) => [$field['key'] => $field['type']['name']])->all();
        foreach (['image' => 'file_reference', 'name' => 'single_line_text_field', 'link' => 'url'] as $key => $type) {
            if (($fields[$key] ?? null) !== $type) {
                throw new RuntimeException('The Shopify preview card definition has changed. Please ask an administrator to review it.');
            }
        }

        return $fields;
    }

    public function parents(): array
    {
        $result = [];
        $after = null;
        do {
            $data = $this->client->graphql(<<<'GQL'
query VibeParents($after: String) { collections(first: 100, after: $after) {
  nodes { id title handle image { url } updatedAt
    metafield(namespace: "custom", key: "shop_your_vibe_preview") { type value }
  } pageInfo { hasNextPage endCursor }
} }
GQL, ['after' => $after]);
            foreach ($data['collections']['nodes'] as $node) {
                $ids = $this->references($node['metafield']);
                if ($ids !== []) {
                    $result[] = ['gid' => $node['id'], 'title' => $node['title'], 'handle' => $node['handle'],
                        'image' => data_get($node, 'image.url'), 'count' => count($ids), 'updated_at' => $node['updatedAt']];
                }
            }
            $after = $this->cursor($data['collections']);
        } while ($after !== null);

        return $result;
    }

    public function parent(string $gid): array
    {
        $this->gid($gid, 'Collection');
        $data = $this->client->graphql(<<<'GQL'
query VibeParent($id: ID!) { collection(id: $id) { id title handle image { url }
  metafield(namespace: "custom", key: "shop_your_vibe_preview") { type value compareDigest }
} }
GQL, ['id' => $gid]);
        $node = $data['collection'] ?? throw new RuntimeException('This Shopify collection is no longer available.');
        $ids = $this->references($node['metafield']);
        $cards = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $objects = $this->client->graphql(<<<'GQL'
query VibeCards($ids: [ID!]!) { nodes(ids: $ids) { ... on Metaobject {
  id type handle fields { key value reference { ... on MediaImage { image { url } } } }
} } }
GQL, ['ids' => $chunk]);
            $byId = collect($objects['nodes'])->filter()->keyBy('id');
            foreach ($chunk as $id) {
                $object = $byId->get($id);
                if (! $object || $object['type'] !== self::TYPE) {
                    throw new RuntimeException('A preview card is missing or uses a different definition. Refresh after correcting it in Shopify.');
                }
                $fields = collect($object['fields'])->keyBy('key');
                $link = (string) data_get($fields->get('link'), 'value', '');
                $cards[] = ['key' => $id, 'id' => $id, 'handle' => $object['handle'],
                    'name' => (string) data_get($fields->get('name'), 'value', ''),
                    'image' => (string) data_get($fields->get('image'), 'value', ''),
                    'image_url' => data_get($fields->get('image'), 'reference.image.url'), 'link' => $link,
                    'collection_gid' => $this->resolveLink($link)];
            }
        }

        return ['parent' => ['gid' => $gid, 'title' => $node['title'], 'handle' => $node['handle'], 'image' => data_get($node, 'image.url')],
            'cards' => $cards, 'reference_ids' => $ids, 'collections' => [], 'digest' => data_get($node, 'metafield.compareDigest')];
    }

    public function resolveLink(string $link): ?string
    {
        $host = parse_url($link, PHP_URL_HOST);
        $allowed = array_filter([parse_url(config('services.shopify.storefront_url'), PHP_URL_HOST), config('services.shopify.shop')]);
        if ($host && ! in_array(strtolower($host), array_map('strtolower', $allowed), true)) {
            return null;
        }
        if (! preg_match('~^/collections/([^/]+)/?$~', (string) parse_url($link, PHP_URL_PATH), $match)) {
            return null;
        }
        $handle = rawurldecode($match[1]);
        $local = ShopifyCollection::query()->where('handle', $handle)->whereNotNull('shopify_id')->latest('id')->value('shopify_id');
        if ($local && preg_match('~^gid://shopify/Collection/\d+$~', $local)) {
            return $local;
        }
        $data = $this->client->graphql(<<<'GQL'
query VibeLinkedCollection($query: String!) { collections(first: 10, query: $query) { nodes { id handle } } }
GQL, ['query' => 'handle:'.json_encode($handle)]);

        return collect($data['collections']['nodes'])->firstWhere('handle', $handle)['id'] ?? null;
    }

    public function collection(string $gid): array
    {
        $this->gid($gid, 'Collection');
        $after = null;
        $products = [];
        do {
            $data = $this->client->graphql(<<<'GQL'
query VibeProducts($id: ID!, $after: String) { collection(id: $id) {
  id title handle sortOrder ruleSet { appliedDisjunctively }
  products(first: 100, after: $after, sortKey: COLLECTION_DEFAULT) {
    nodes { id title featuredImage { url } variants(first: 1) { nodes { sku } } }
    pageInfo { hasNextPage endCursor }
  }
} }
GQL, ['id' => $gid, 'after' => $after]);
            $node = $data['collection'] ?? throw new RuntimeException('The linked collection is no longer available in Shopify.');
            foreach ($node['products']['nodes'] as $product) {
                $products[] = ['id' => $product['id'], 'title' => $product['title'],
                    'image' => data_get($product, 'featuredImage.url'), 'sku' => data_get($product, 'variants.nodes.0.sku')];
            }
            $after = $this->cursor($node['products']);
        } while ($after !== null);

        return ['gid' => $gid, 'title' => $node['title'], 'handle' => $node['handle'], 'sort' => $node['sortOrder'],
            'manual_supported' => in_array($node['sortOrder'], ['MANUAL', 'BEST_SELLING', 'ALPHA_ASC', 'ALPHA_DESC', 'PRICE_ASC', 'PRICE_DESC', 'CREATED', 'CREATED_DESC'], true),
            'membership_supported' => $node['ruleSet'] === null, 'enable_manual' => false, 'products' => $products];
    }

    public function images(string $search = '', ?string $after = null): array
    {
        $data = $this->client->graphql(<<<'GQL'
query VibeImages($query: String!, $after: String) { files(first: 24, after: $after, query: $query) {
  nodes { ... on MediaImage { id alt fileStatus image { url } } } pageInfo { hasNextPage endCursor }
} }
GQL, ['query' => 'media_type:IMAGE'.($search !== '' ? ' AND filename:'.json_encode('*'.$search.'*') : ''), 'after' => $after]);

        return ['images' => array_values(array_filter($data['files']['nodes'], fn ($image) => ($image['fileStatus'] ?? '') === 'READY')),
            'after' => $this->cursor($data['files'])];
    }

    public function saveCard(array $card): string
    {
        $fields = collect(['name', 'image', 'link'])->map(fn ($key) => ['key' => $key, 'value' => (string) $card[$key]])->all();
        if ($card['id']) {
            $this->gid($card['id'], 'Metaobject');
            $data = $this->mutation(<<<'GQL'
mutation VibeUpdate($id: ID!, $input: MetaobjectUpdateInput!) { metaobjectUpdate(id: $id, metaobject: $input) {
  metaobject { id } userErrors { field message }
} }
GQL, ['id' => $card['id'], 'input' => ['fields' => $fields]], 'metaobjectUpdate');
        } else {
            // A persisted UUID handle makes retries safe after an ambiguous network response.
            $data = $this->mutation(<<<'GQL'
mutation VibeCreate($handle: MetaobjectHandleInput!, $input: MetaobjectUpsertInput!) { metaobjectUpsert(handle: $handle, metaobject: $input) {
  metaobject { id } userErrors { field message }
} }
GQL, ['handle' => ['type' => self::TYPE, 'handle' => $card['handle']], 'input' => ['fields' => $fields]], 'metaobjectUpsert');
        }

        return $data['metaobject']['id'] ?? throw new RuntimeException('Shopify did not confirm the saved card.');
    }

    public function setReferences(string $gid, array $ids, ?string $digest): void
    {
        $this->gid($gid, 'Collection');
        foreach ($ids as $id) {
            $this->gid($id, 'Metaobject');
        }
        $data = $this->mutation(<<<'GQL'
mutation VibeReferences($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) {
  metafields { value } userErrors { field message }
} }
GQL, ['metafields' => [['ownerId' => $gid, 'namespace' => 'custom', 'key' => self::KEY,
            'type' => 'list.metaobject_reference', 'value' => json_encode(array_values($ids)), 'compareDigest' => $digest]]], 'metafieldsSet');
        if (json_decode($data['metafields'][0]['value'] ?? 'null', true) !== array_values($ids)) {
            throw new RuntimeException('Shopify did not confirm the preview card order.');
        }
    }

    public function enableManual(string $gid): void
    {
        $data = $this->mutation(<<<'GQL'
mutation VibeManual($input: CollectionInput!) { collectionUpdate(input: $input) {
  collection { id sortOrder } userErrors { field message }
} }
GQL, ['input' => ['id' => $gid, 'sortOrder' => 'MANUAL']], 'collectionUpdate');
        if (($data['collection']['sortOrder'] ?? '') !== 'MANUAL') {
            throw new RuntimeException('Shopify did not confirm manual sorting.');
        }
    }

    public function addProducts(string $gid, array $ids): void
    {
        $this->mutation(<<<'GQL'
mutation VibeAddProducts($id: ID!, $ids: [ID!]!) { collectionAddProducts(id: $id, productIds: $ids) {
  collection { id } userErrors { field message }
} }
GQL, ['id' => $gid, 'ids' => array_values($ids)], 'collectionAddProducts');
    }

    public function removeProducts(string $gid, array $ids): string
    {
        $data = $this->mutation(<<<'GQL'
mutation VibeRemoveProducts($id: ID!, $ids: [ID!]!) { collectionRemoveProducts(id: $id, productIds: $ids) {
  job { id } userErrors { field message }
} }
GQL, ['id' => $gid, 'ids' => array_values($ids)], 'collectionRemoveProducts');

        return $data['job']['id'] ?? throw new RuntimeException('Shopify did not return the membership job.');
    }

    public function reorderProducts(string $gid, array $moves): string
    {
        $data = $this->mutation(<<<'GQL'
mutation VibeReorderProducts($id: ID!, $moves: [MoveInput!]!) { collectionReorderProducts(id: $id, moves: $moves) {
  job { id } userErrors { field message }
} }
GQL, ['id' => $gid, 'moves' => $moves], 'collectionReorderProducts');

        return $data['job']['id'] ?? throw new RuntimeException('Shopify did not return the ordering job.');
    }

    public function jobDone(string $gid): bool
    {
        $data = $this->client->graphql('query VibeJob($id: ID!) { job(id: $id) { id done } }', ['id' => $gid]);
        if (! isset($data['job'])) {
            throw new RuntimeException('The Shopify job could not be confirmed. Changes remain pending.');
        }

        return $data['job']['done'] === true;
    }

    private function mutation(string $query, array $variables, string $key): array
    {
        $data = $this->client->graphql($query, $variables);
        $payload = $data[$key] ?? throw new RuntimeException('Shopify returned an incomplete response.');
        if (! array_key_exists('userErrors', $payload) || $payload['userErrors'] !== []) {
            throw new RuntimeException(collect($payload['userErrors'] ?? [])->pluck('message')->implode('; ') ?: 'Shopify did not confirm the operation.');
        }

        return $payload;
    }

    private function references(?array $metafield): array
    {
        if ($metafield === null) {
            return [];
        }
        if ($metafield['type'] !== 'list.metaobject_reference') {
            throw new RuntimeException('The preview collection field has an unexpected type.');
        }
        $ids = json_decode($metafield['value'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new RuntimeException('The preview card reference list is invalid.');
        }
        foreach ($ids as $id) {
            $this->gid($id, 'Metaobject');
        }

        return $ids;
    }

    private function cursor(array $connection): ?string
    {
        if ($connection['pageInfo']['hasNextPage']) {
            return $connection['pageInfo']['endCursor'] ?: throw new RuntimeException('Shopify returned incomplete pagination.');
        }

        return null;
    }

    public function gid(string $id, string $type): void
    {
        if (! preg_match('~^gid://shopify/'.$type.'/\d+$~', $id)) {
            throw new RuntimeException('This record does not have a valid Shopify identifier.');
        }
    }
}
