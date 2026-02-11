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

        $data = $this->client->graphql($this->mutation(), [
            'input' => $input,
        ]);

        $errors = data_get($data, 'collectionUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            $messages = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
        }

        return data_get($data, 'collectionUpdate.collection', []);
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

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
