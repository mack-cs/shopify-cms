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
        $touchedIds = [];
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

            $updateData = [
                'handle' => $handle,
                'title' => data_get($collection, 'title'),
                'description_html' => data_get($collection, 'descriptionHtml'),
                'seo_title' => data_get($collection, 'seo.title'),
                'seo_description' => data_get($collection, 'seo.description'),
            ];

            $hasDeindexMetafield = data_get($collection, 'deindex_metafield_hidden.value') !== null
                || data_get($collection, 'deindex_metafield_hide_from_google.value') !== null;
            if ($hasDeindexMetafield) {
                $updateData['deindex'] = $this->boolFromMetafield(
                    data_get($collection, 'deindex_metafield_hidden.value')
                        ?? data_get($collection, 'deindex_metafield_hide_from_google.value')
                );
            }

            $hasPublications = array_key_exists('resourcePublications', $collection);
            if ($hasPublications) {
                $updateData['published_on_online_store_only'] = $this->isPublishedOnOnlineStoreOnly($collection);
                $updateData['published_channel_names'] = $this->publishedChannelNames($collection);
            }

            $saved = ShopifyCollection::withoutEvents(fn () => ShopifyCollection::updateOrCreate(
                [
                    'import_id' => $import->id,
                    'shopify_id' => $shopifyId,
                ],
                $updateData
            ));

            $touchedIds[] = $saved->id;
        }

        if (!empty($touchedIds)) {
            ShopifyCollection::whereIn('id', array_unique($touchedIds))->increment('approval_version');
        }

        $import->update(['status' => 'ready', 'is_valid' => true]);
    }

    private function fetchCollections(): \Generator
    {
        $after = null;
        do {
            $data = $this->queryCollectionsPage($this->collectionsQuery(), $this->collectionsQueryBasic(), $after);

            $collections = data_get($data, 'collections.nodes', []);
            foreach ($collections as $collection) {
                yield $collection;
            }

            $pageInfo = data_get($data, 'collections.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after);
    }

    private function collectionsQuery(): string
    {
        return <<<'GQL'
query Collections($first: Int!, $after: String) {
  collections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      handle
      title
      descriptionHtml
      seo { title description }
      deindex_metafield_hidden: metafield(namespace: "seo", key: "hidden") { value }
      deindex_metafield_hide_from_google: metafield(namespace: "seo", key: "hide_from_google") { value }
      resourcePublications(first: 20, onlyPublished: true) {
        nodes {
          publication {
            name
          }
        }
      }
    }
  }
}
GQL;
    }

    private function collectionsQueryBasic(): string
    {
        return <<<'GQL'
query Collections($first: Int!, $after: String) {
  collections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      handle
      title
      descriptionHtml
      seo { title description }
      deindex_metafield_hidden: metafield(namespace: "seo", key: "hidden") { value }
      deindex_metafield_hide_from_google: metafield(namespace: "seo", key: "hide_from_google") { value }
    }
  }
}
GQL;
    }

    private function queryCollectionsPage(string $query, string $fallbackQuery, ?string $after): array
    {
        $variables = [
            'first' => 100,
            'after' => $after,
        ];

        try {
            return $this->client->graphql($query, $variables);
        } catch (\Throwable $e) {
            if (!$this->isWorkflowFieldSchemaError($e)) {
                throw $e;
            }
            try {
                return $this->client->graphql($fallbackQuery, $variables);
            } catch (\Throwable $fallbackException) {
                if (!$this->isWorkflowFieldSchemaError($fallbackException)) {
                    throw $fallbackException;
                }

                // Final fallback when both publications and metafields are restricted.
                return $this->client->graphql($this->collectionsQueryMinimal(), $variables);
            }
        }
    }

    private function collectionsQueryMinimal(): string
    {
        return <<<'GQL'
query Collections($first: Int!, $after: String) {
  collections(first: $first, after: $after) {
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

    private function isWorkflowFieldSchemaError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $isMissingField = str_contains($message, 'cannot query field')
            && (str_contains($message, 'resourcepublications') || str_contains($message, 'metafield'));

        $isAccessDeniedOptional = str_contains($message, 'access denied')
            && (
                str_contains($message, 'resourcepublications')
                || str_contains($message, 'read_publications')
                || str_contains($message, 'metafield')
            );

        return $isMissingField || $isAccessDeniedOptional;
    }

    private function publishedChannelNames(array $collection): ?string
    {
        $names = collect(data_get($collection, 'resourcePublications.nodes', []))
            ->pluck('publication.name')
            ->filter(fn ($name) => trim((string) $name) !== '')
            ->map(fn ($name) => trim((string) $name))
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return null;
        }

        return $names->implode(', ');
    }

    private function isPublishedOnOnlineStoreOnly(array $collection): bool
    {
        $names = collect(data_get($collection, 'resourcePublications.nodes', []))
            ->pluck('publication.name')
            ->filter(fn ($name) => trim((string) $name) !== '')
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->unique()
            ->values();

        return $names->count() === 1 && $names->first() === 'online store';
    }

    private function boolFromMetafield(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }
}
