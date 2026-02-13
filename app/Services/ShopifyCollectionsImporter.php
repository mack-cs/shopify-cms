<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ShopifyCollection;

final class ShopifyCollectionsImporter
{
    public function __construct(
        private readonly ShopifyApiClient $client,
    ) {}

    public function createOrReuseCollectionsImport(int $userId): Import
    {
        $existing = Import::where('filename', 'shopify-collections')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->update([
                'status' => 'processing',
                'created_by' => $userId,
                'is_valid' => true,
            ]);
            return $existing;
        }

        return Import::create([
            'filename' => 'shopify-collections',
            'mode' => 'overwrite',
            'status' => 'processing',
            'created_by' => $userId,
            'is_current' => false,
            'is_valid' => true,
        ]);
    }

    public function importIntoExistingImport(Import $import): void
    {
        $seen = [];
        foreach ($this->fetchCollections() as $collection) {
            $shopifyId = trim((string) data_get($collection, 'id', ''));
            $handle = trim((string) data_get($collection, 'handle', ''));
            if ($shopifyId === '' || $handle === '') {
                continue;
            }
            if (isset($seen[$shopifyId])) {
                continue;
            }
            $seen[$shopifyId] = true;

            ShopifyCollection::updateOrCreate(
                [
                    'import_id' => $import->id,
                    'shopify_id' => $shopifyId,
                ],
                [
                    'handle' => $handle,
                    'title' => data_get($collection, 'title'),
                    'description_html' => data_get($collection, 'descriptionHtml'),
                    'seo_title' => data_get($collection, 'seo.title'),
                    'seo_description' => data_get($collection, 'seo.description'),
                ]
            );
        }

        $import->update(['status' => 'ready', 'is_valid' => true]);
    }

    private function fetchCollections(): \Generator
    {
        yield from $this->fetchCustomCollections();
        yield from $this->fetchSmartCollections();
    }

    private function fetchCustomCollections(): \Generator
    {
        $after = null;
        do {
            $data = $this->client->graphql(
                $this->customCollectionsQuery(),
                [
                    'first' => 100,
                    'after' => $after,
                ]
            );

            $collections = data_get($data, 'customCollections.nodes', []);
            foreach ($collections as $collection) {
                yield $collection;
            }

            $pageInfo = data_get($data, 'customCollections.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after);
    }

    private function fetchSmartCollections(): \Generator
    {
        $after = null;
        do {
            $data = $this->client->graphql(
                $this->smartCollectionsQuery(),
                [
                    'first' => 100,
                    'after' => $after,
                ]
            );

            $collections = data_get($data, 'smartCollections.nodes', []);
            foreach ($collections as $collection) {
                yield $collection;
            }

            $pageInfo = data_get($data, 'smartCollections.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after);
    }

    private function customCollectionsQuery(): string
    {
        return <<<'GQL'
query CustomCollections($first: Int!, $after: String) {
  customCollections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      handle
      title
      descriptionHtml
      seo { title description }
    }
  }
}
GQL;
    }

    private function smartCollectionsQuery(): string
    {
        return <<<'GQL'
query SmartCollections($first: Int!, $after: String) {
  smartCollections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      handle
      title
      descriptionHtml
      seo { title description }
    }
  }
}
GQL;
    }
}
