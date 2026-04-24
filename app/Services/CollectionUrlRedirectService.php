<?php

namespace App\Services;

use App\Models\CollectionUrlRedirect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use SplTempFileObject;

class CollectionUrlRedirectService
{
    /**
     * @param Collection<int, CollectionUrlRedirect> $redirects
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
            if (!$redirect instanceof CollectionUrlRedirect) {
                continue;
            }

            if (in_array($redirect->status, [
                CollectionUrlRedirect::STATUS_IGNORED,
                CollectionUrlRedirect::STATUS_SYNCED,
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

    public function syncRedirect(CollectionUrlRedirect $redirect): CollectionUrlRedirect
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
     * @param Collection<int, CollectionUrlRedirect> $redirects
     * @return array{disk:string,path:string,filename:string,row_count:int}
     */
    public function exportRedirects(Collection $redirects): array
    {
        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne(['Redirect from', 'Redirect to']);

        $rowCount = 0;
        foreach ($redirects as $redirect) {
            if (!$redirect instanceof CollectionUrlRedirect) {
                continue;
            }

            $writer->insertOne([
                $redirect->path,
                $redirect->target,
            ]);
            $rowCount++;
        }

        $filename = 'collection-url-redirects-' . now()->format('Ymd_His') . '.csv';
        $path = "redirect-exports/{$filename}";
        Storage::disk('public')->put($path, $writer->toString());

        return [
            'disk' => 'public',
            'path' => $path,
            'filename' => $filename,
            'row_count' => $rowCount,
        ];
    }

    private function client(): ShopifyApiClient
    {
        return app(ShopifyApiClient::class);
    }

    private function updateRedirect(CollectionUrlRedirect $redirect, string $shopifyRedirectId, string $path, string $target): CollectionUrlRedirect
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

    private function markSynced(CollectionUrlRedirect $redirect, string $shopifyRedirectId): CollectionUrlRedirect
    {
        $redirect->forceFill([
            'status' => CollectionUrlRedirect::STATUS_SYNCED,
            'shopify_redirect_id' => $shopifyRedirectId,
            'last_error' => null,
            'synced_at' => now(),
        ])->save();

        return $redirect->fresh();
    }

    private function markFailed(CollectionUrlRedirect $redirect, string $message): void
    {
        $redirect->forceFill([
            'status' => CollectionUrlRedirect::STATUS_FAILED,
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
}
