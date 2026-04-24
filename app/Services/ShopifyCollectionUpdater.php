<?php

namespace App\Services;

use App\Models\ShopifyCollection;

final class ShopifyCollectionUpdater
{
    public function __construct(
        private readonly ShopifyApiClient $client,
    ) {}

    public function update(ShopifyCollection $collection, array $fields): array
    {
        $input = [
            'id' => $collection->shopify_id,
        ];

        if (array_key_exists('handle', $fields)) {
            $handle = $this->nullIfEmpty($fields['handle']);
            if ($handle === null) {
                throw new \RuntimeException('Handle cannot be empty when pushing a URL change.');
            }
            $input['handle'] = $handle;
        }

        if (array_key_exists('title', $fields)) {
            $input['title'] = $this->nullIfEmpty($fields['title']);
        }

        if (array_key_exists('description_html', $fields)) {
            $input['descriptionHtml'] = $this->nullIfEmpty($fields['description_html']);
        }

        $seo = [];
        if (array_key_exists('seo_title', $fields)) {
            $seo['title'] = $this->nullIfEmpty($fields['seo_title']);
        }
        if (array_key_exists('seo_description', $fields)) {
            $seo['description'] = $this->nullIfEmpty($fields['seo_description']);
        }
        if ($seo !== []) {
            $input['seo'] = $seo;
        }

        $result = [];
        if (count($input) > 1) {
            $data = $this->client->graphql($this->mutation(), [
                'input' => $input,
            ]);

            $errors = data_get($data, 'collectionUpdate.userErrors', []);
            if (is_array($errors) && !empty($errors)) {
                $messages = collect($errors)->pluck('message')->filter()->implode('; ');
                throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
            }

            $result = data_get($data, 'collectionUpdate.collection', []);
        }

        if (array_key_exists('deindex', $fields) && $fields['deindex'] !== null) {
            $this->setDeindexMetafield($collection->shopify_id, (bool) $fields['deindex']);
        }

        $metafields = [];

        if (array_key_exists('footer_title', $fields)) {
            $metafields[] = [
                'ownerId' => $collection->shopify_id,
                'namespace' => 'custom',
                'key' => 'footer_description',
                'type' => 'single_line_text_field',
                'value' => $this->metafieldStringValue($fields['footer_title']),
            ];
        }

        if (array_key_exists('elegant_footer_description', $fields)) {
            $metafields[] = [
                'ownerId' => $collection->shopify_id,
                'namespace' => 'custom',
                'key' => 'elegant_footer_description',
                'type' => 'single_line_text_field',
                'value' => $this->metafieldStringValue($fields['elegant_footer_description']),
            ];
        }

        if ($metafields !== []) {
            $this->setMetafields($metafields);
        }

        return $result;
    }

    private function mutation(): string
    {
        return <<<'GQL'
mutation CollectionUpdate($input: CollectionInput!) {
  collectionUpdate(input: $input) {
    collection {
      id
      handle
      title
      descriptionHtml
      seo { title description }
    }
    userErrors { field message }
  }
}
GQL;
    }

    private function setDeindexMetafield(string $ownerId, bool $deindex): void
    {
        $this->setMetafields([[
            'ownerId' => $ownerId,
            'namespace' => 'seo',
            'key' => 'hide_from_google',
            'type' => 'boolean',
            'value' => $deindex ? 'true' : 'false',
        ]], 'Failed to update collection deindex metafield.');
    }

    /**
     * @param array<int, array<string, string>> $metafields
     */
    private function setMetafields(array $metafields, string $fallbackMessage = 'Failed to update collection metafields.'): void
    {
        $data = $this->client->graphql($this->metafieldsSetMutation(), [
            'metafields' => $metafields,
        ]);

        $errors = data_get($data, 'metafieldsSet.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            $messages = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException($messages !== '' ? $messages : $fallbackMessage);
        }
    }

    private function metafieldsSetMutation(): string
    {
        return <<<'GQL'
mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function metafieldStringValue(mixed $value): string
    {
        return $this->nullIfEmpty($value) ?? '';
    }
}
