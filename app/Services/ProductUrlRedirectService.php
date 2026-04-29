<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUrlRedirect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;

class ProductUrlRedirectService
{
    /**
     * @param Collection<int, ProductUrlRedirect> $redirects
     * @return array{synced:int,failed:int,skipped:int,errors:array<int, string>}
     */
    public function syncRedirects(Collection $redirects): array
    {
        $summary = [
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($redirects as $redirect) {
            if (!$redirect instanceof ProductUrlRedirect) {
                continue;
            }

            if (in_array($redirect->status, [
                ProductUrlRedirect::STATUS_IGNORED,
                ProductUrlRedirect::STATUS_SYNCED,
            ], true)) {
                $summary['skipped']++;
                continue;
            }

            try {
                $this->syncRedirect($redirect);
                $summary['synced']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = "Redirect {$redirect->id}: {$e->getMessage()}";
            }
        }

        return $summary;
    }

    public function syncRedirect(ProductUrlRedirect $redirect): ProductUrlRedirect
    {
        $path = trim((string) $redirect->path);
        $target = trim((string) $redirect->target);

        if ($path === '' || $target === '') {
            $this->markFailed($redirect, 'Redirect path or target is missing.');
            throw new \RuntimeException('Redirect path or target is missing.');
        }

        $existingId = trim((string) ($redirect->shopify_redirect_id ?? ''));
        if ($existingId !== '') {
            return $this->updateRedirect($redirect, $existingId, $path, $target);
        }

        $existingRedirect = $this->findRedirectByPath($path);
        if ($existingRedirect !== null) {
            $existingShopifyId = trim((string) ($existingRedirect['id'] ?? ''));
            $existingTarget = trim((string) ($existingRedirect['target'] ?? ''));

            if ($existingShopifyId !== '') {
                if ($existingTarget === $target) {
                    return $this->markSynced($redirect, $existingShopifyId);
                }

                return $this->updateRedirect($redirect, $existingShopifyId, $path, $target);
            }
        }

        $payload = $this->client()->graphql($this->urlRedirectCreateMutation(), [
            'input' => [
                'path' => $path,
                'target' => $target,
            ],
        ]);

        $result = $payload['urlRedirectCreate'] ?? null;
        if (!is_array($result)) {
            $this->markFailed($redirect, 'Missing urlRedirectCreate payload.');
            throw new \RuntimeException('Missing urlRedirectCreate payload.');
        }

        $errors = $result['userErrors'] ?? [];
        if (is_array($errors) && !empty($errors)) {
            $message = $this->formatUserErrors($errors);
            $this->markFailed($redirect, $message);
            throw new \RuntimeException($message);
        }

        $shopifyRedirectId = trim((string) data_get($result, 'urlRedirect.id', ''));
        if ($shopifyRedirectId === '') {
            $this->markFailed($redirect, 'Shopify did not return a redirect ID.');
            throw new \RuntimeException('Shopify did not return a redirect ID.');
        }

        return $this->markSynced($redirect, $shopifyRedirectId);
    }

    /**
     * @param Collection<int, ProductUrlRedirect> $redirects
     * @return array{disk:string,path:string,filename:string,row_count:int}
     */
    public function exportRedirects(Collection $redirects): array
    {
        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne(['Redirect from', 'Redirect to']);

        $rowCount = 0;
        foreach ($redirects as $redirect) {
            if (!$redirect instanceof ProductUrlRedirect) {
                continue;
            }

            $writer->insertOne([
                $redirect->path,
                $redirect->target,
            ]);
            $rowCount++;
        }

        $filename = 'product-url-redirects-' . now()->format('Ymd_His') . '.csv';
        $path = "redirect-exports/{$filename}";
        Storage::disk('public')->put($path, $writer->toString());

        return [
            'disk' => 'public',
            'path' => $path,
            'filename' => $filename,
            'row_count' => $rowCount,
        ];
    }

    /**
     * @param Collection<int, ProductUrlRedirect> $redirects
     * @return array{disk:string,path:string,filename:string,row_count:int}
     */
    public function exportHistory(Collection $redirects): array
    {
        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne([
            'product_id',
            'product_handle',
            'product_shopify_id',
            'created_by_user_id',
            'created_by_email',
            'old_handle',
            'new_handle',
            'path',
            'target',
            'status',
            'shopify_redirect_id',
            'last_error',
            'synced_at',
            'created_at',
            'updated_at',
        ]);

        $rowCount = 0;
        foreach ($redirects as $redirect) {
            if (!$redirect instanceof ProductUrlRedirect) {
                continue;
            }

            $redirect->loadMissing(['product:id,handle,shopify_id', 'creator:id,email']);

            $writer->insertOne([
                $redirect->product_id,
                $redirect->product?->handle,
                $redirect->product?->shopify_id,
                $redirect->created_by,
                $redirect->creator?->email,
                $redirect->old_handle,
                $redirect->new_handle,
                $redirect->path,
                $redirect->target,
                $redirect->status,
                $redirect->shopify_redirect_id,
                $redirect->last_error,
                $redirect->synced_at?->format('Y-m-d H:i:s'),
                $redirect->created_at?->format('Y-m-d H:i:s'),
                $redirect->updated_at?->format('Y-m-d H:i:s'),
            ]);
            $rowCount++;
        }

        $filename = 'product-url-redirect-history-' . now()->format('Ymd_His') . '.csv';
        $path = "redirect-exports/{$filename}";
        Storage::disk('public')->put($path, $writer->toString());

        return [
            'disk' => 'public',
            'path' => $path,
            'filename' => $filename,
            'row_count' => $rowCount,
        ];
    }

    /**
     * @return array{total:int,created:int,updated:int,skipped_missing_product:int,skipped_invalid:int}
     */
    public function importHistoryFromPath(string $absolutePath): array
    {
        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $result = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_missing_product' => 0,
            'skipped_invalid' => 0,
        ];

        foreach ($csv->getRecords() as $row) {
            $result['total']++;

            $path = $this->nullableString($row['path'] ?? null)
                ?? $this->pathFromHandle($row['old_handle'] ?? null);
            $target = $this->nullableString($row['target'] ?? null)
                ?? $this->targetFromHandle($row['new_handle'] ?? null);
            $oldHandle = $this->nullableString($row['old_handle'] ?? null)
                ?? $this->handleFromPath($path);
            $newHandle = $this->nullableString($row['new_handle'] ?? null)
                ?? $this->handleFromPath($target);

            if ($path === null || $target === null || $oldHandle === null || $newHandle === null) {
                $result['skipped_invalid']++;
                continue;
            }

            $product = $this->resolveProductForHistoryImport($row, $newHandle);
            if (!$product instanceof Product) {
                $result['skipped_missing_product']++;
                continue;
            }

            $existing = ProductUrlRedirect::query()
                ->where('path', $path)
                ->first(['id', 'created_at', 'created_by']);

            $createdAt = $this->parseTimestamp($row['created_at'] ?? null)
                ?? ($existing?->created_at?->format('Y-m-d H:i:s'))
                ?? now()->format('Y-m-d H:i:s');
            $updatedAt = $this->parseTimestamp($row['updated_at'] ?? null)
                ?? $createdAt;

            $createdBy = $this->resolveCreatedByForHistoryImport($row)
                ?? $existing?->created_by;

            DB::table('product_url_redirects')->updateOrInsert(
                ['path' => $path],
                [
                    'product_id' => $product->id,
                    'created_by' => $createdBy,
                    'old_handle' => $oldHandle,
                    'new_handle' => $newHandle,
                    'target' => $target,
                    'status' => $this->normalizeImportedStatus($row['status'] ?? null),
                    'shopify_redirect_id' => $this->nullableString($row['shopify_redirect_id'] ?? null),
                    'last_error' => $this->nullableString($row['last_error'] ?? null),
                    'synced_at' => $this->parseTimestamp($row['synced_at'] ?? null),
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]
            );

            if ($existing) {
                $result['updated']++;
            } else {
                $result['created']++;
            }
        }

        return $result;
    }

    private function client(): ShopifyApiClient
    {
        return app(ShopifyApiClient::class);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveProductForHistoryImport(array $row, ?string $fallbackHandle): ?Product
    {
        $shopifyId = $this->nullableString($row['product_shopify_id'] ?? null);
        if ($shopifyId !== null) {
            $product = Product::query()->where('shopify_id', $shopifyId)->first();
            if ($product instanceof Product) {
                return $product;
            }
        }

        $handleCandidates = array_filter([
            $this->nullableString($row['product_handle'] ?? null),
            $fallbackHandle,
            $this->handleFromPath($this->nullableString($row['target'] ?? null)),
            $this->nullableString($row['new_handle'] ?? null),
        ]);

        foreach ($handleCandidates as $handle) {
            $product = Product::query()->where('handle', $handle)->first();
            if ($product instanceof Product) {
                return $product;
            }
        }

        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId > 0) {
            $product = Product::query()->find($productId);
            if ($product instanceof Product) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveCreatedByForHistoryImport(array $row): ?int
    {
        $email = $this->nullableString($row['created_by_email'] ?? null);
        if ($email !== null) {
            $userId = User::query()->where('email', $email)->value('id');
            if ($userId) {
                return (int) $userId;
            }
        }

        $userId = (int) ($row['created_by_user_id'] ?? 0);
        if ($userId > 0 && User::query()->whereKey($userId)->exists()) {
            return $userId;
        }

        return null;
    }

    private function updateRedirect(ProductUrlRedirect $redirect, string $shopifyRedirectId, string $path, string $target): ProductUrlRedirect
    {
        $payload = $this->client()->graphql($this->urlRedirectUpdateMutation(), [
            'id' => $shopifyRedirectId,
            'input' => [
                'path' => $path,
                'target' => $target,
            ],
        ]);

        $result = $payload['urlRedirectUpdate'] ?? null;
        if (!is_array($result)) {
            $this->markFailed($redirect, 'Missing urlRedirectUpdate payload.');
            throw new \RuntimeException('Missing urlRedirectUpdate payload.');
        }

        $errors = $result['userErrors'] ?? [];
        if (is_array($errors) && !empty($errors)) {
            $message = $this->formatUserErrors($errors);
            $this->markFailed($redirect, $message);
            throw new \RuntimeException($message);
        }

        return $this->markSynced(
            $redirect,
            trim((string) data_get($result, 'urlRedirect.id', $shopifyRedirectId)) ?: $shopifyRedirectId
        );
    }

    /**
     * @return array{id:string,path:string,target:string}|null
     */
    private function findRedirectByPath(string $path): ?array
    {
        $escapedPath = str_replace(['\\', '\''], ['\\\\', '\\\''], $path);
        $data = $this->client()->graphql($this->urlRedirectsByPathQuery(), [
            'query' => "path:'{$escapedPath}'",
        ]);

        $node = data_get($data, 'urlRedirects.nodes.0');
        if (!is_array($node)) {
            return null;
        }

        $id = trim((string) ($node['id'] ?? ''));
        $foundPath = trim((string) ($node['path'] ?? ''));
        $target = trim((string) ($node['target'] ?? ''));
        if ($id === '' || $foundPath === '') {
            return null;
        }

        return [
            'id' => $id,
            'path' => $foundPath,
            'target' => $target,
        ];
    }

    private function markSynced(ProductUrlRedirect $redirect, string $shopifyRedirectId): ProductUrlRedirect
    {
        $redirect->forceFill([
            'status' => ProductUrlRedirect::STATUS_SYNCED,
            'shopify_redirect_id' => $shopifyRedirectId,
            'last_error' => null,
            'synced_at' => now(),
        ])->save();

        return $redirect->fresh();
    }

    private function markFailed(ProductUrlRedirect $redirect, string $message): void
    {
        $redirect->forceFill([
            'status' => ProductUrlRedirect::STATUS_FAILED,
            'last_error' => $message,
        ])->save();
    }

    /**
     * @param array<int, array{field?:array<int, string>|null,message?:string|null}> $errors
     */
    private function formatUserErrors(array $errors): string
    {
        return collect($errors)
            ->map(function (array $error): string {
                $field = collect($error['field'] ?? [])->filter()->implode('.');
                $message = trim((string) ($error['message'] ?? 'Shopify redirect error.'));
                return $field !== '' ? "{$field}: {$message}" : $message;
            })
            ->filter()
            ->implode('; ');
    }

    private function urlRedirectCreateMutation(): string
    {
        return <<<'GQL'
mutation UrlRedirectCreate($input: UrlRedirectInput!) {
  urlRedirectCreate(urlRedirect: $input) {
    urlRedirect {
      id
      path
      target
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function urlRedirectUpdateMutation(): string
    {
        return <<<'GQL'
mutation UrlRedirectUpdate($id: ID!, $input: UrlRedirectInput!) {
  urlRedirectUpdate(id: $id, urlRedirect: $input) {
    urlRedirect {
      id
      path
      target
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function urlRedirectsByPathQuery(): string
    {
        return <<<'GQL'
query UrlRedirectsByPath($query: String!) {
  urlRedirects(first: 1, query: $query) {
    nodes {
      id
      path
      target
    }
  }
}
GQL;
    }

    private function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) ($value ?? ''));

        return $resolved === '' ? null : $resolved;
    }

    private function normalizeImportedStatus(mixed $value): string
    {
        $status = strtolower(trim((string) ($value ?? '')));

        return match ($status) {
            ProductUrlRedirect::STATUS_SYNCED => ProductUrlRedirect::STATUS_SYNCED,
            ProductUrlRedirect::STATUS_FAILED => ProductUrlRedirect::STATUS_FAILED,
            ProductUrlRedirect::STATUS_IGNORED => ProductUrlRedirect::STATUS_IGNORED,
            default => ProductUrlRedirect::STATUS_PENDING,
        };
    }

    private function parseTimestamp(mixed $value): ?string
    {
        $resolved = $this->nullableString($value);
        if ($resolved === null) {
            return null;
        }

        try {
            return Carbon::parse($resolved)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function handleFromPath(?string $path): ?string
    {
        $resolved = $this->nullableString($path);
        if ($resolved === null) {
            return null;
        }

        $resolved = preg_replace('#^https?://[^/]+#i', '', $resolved) ?? $resolved;
        $resolved = trim($resolved, '/');
        if ($resolved === '') {
            return null;
        }

        $parts = explode('/', $resolved);
        $handle = trim((string) end($parts));

        return $handle === '' ? null : $handle;
    }

    private function pathFromHandle(mixed $handle): ?string
    {
        $resolved = $this->nullableString($handle);

        return $resolved === null ? null : "/products/{$resolved}";
    }

    private function targetFromHandle(mixed $handle): ?string
    {
        $resolved = $this->nullableString($handle);

        return $resolved === null ? null : "/products/{$resolved}";
    }
}
