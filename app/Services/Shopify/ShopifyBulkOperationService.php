<?php

namespace App\Services\Shopify;

use App\Models\ShopifySyncRun;
use Illuminate\Support\Carbon;

final class ShopifyBulkOperationService
{
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_EXPIRED = 'EXPIRED';

    public function __construct(
        private readonly ShopifyGraphqlClient $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function start(ShopifySyncRun $run, string $bulkQuery): array
    {
        $query = <<<'GQL'
mutation ShopifyBulkOperationRunQuery($query: String!) {
  bulkOperationRunQuery(query: $query) {
    bulkOperation {
      id
      status
      createdAt
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $data = $this->client->query($query, ['query' => $bulkQuery]);

        $errors = data_get($data, 'bulkOperationRunQuery.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)
                ->map(fn (array $error): string => implode('.', (array) ($error['field'] ?? [])) . ': ' . ($error['message'] ?? 'Unknown error'))
                ->implode('; ');

            throw new \RuntimeException($message !== '' ? $message : 'Shopify rejected the bulk operation.');
        }

        $operation = data_get($data, 'bulkOperationRunQuery.bulkOperation');
        if (!is_array($operation) || blank($operation['id'] ?? null)) {
            throw new \RuntimeException('Shopify did not return a bulk operation ID.');
        }

        $run->forceFill([
            'shopify_operation_id' => $operation['id'],
            'shopify_operation_status' => $operation['status'] ?? null,
            'status' => ShopifySyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'metadata' => array_merge($run->metadata ?? [], [
                'operation_created_at' => $operation['createdAt'] ?? null,
            ]),
        ])->save();

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function poll(ShopifySyncRun $run): array
    {
        $query = <<<'GQL'
query CurrentBulkOperation {
  currentBulkOperation {
    id
    status
    errorCode
    createdAt
    completedAt
    objectCount
    rootObjectCount
    fileSize
    url
    partialDataUrl
  }
}
GQL;

        $data = $this->client->query($query);

        $operation = data_get($data, 'currentBulkOperation');
        if (!is_array($operation)) {
            throw new \RuntimeException('Shopify did not return a current bulk operation.');
        }

        if ($run->shopify_operation_id && ($operation['id'] ?? null) !== $run->shopify_operation_id) {
            $operation = $this->pollNode((string) $run->shopify_operation_id);
        }

        $run->forceFill([
            'shopify_operation_status' => $operation['status'] ?? null,
            'root_object_count' => $this->nullableInt($operation['rootObjectCount'] ?? null),
            'object_count' => $this->nullableInt($operation['objectCount'] ?? null),
            'file_size' => $this->nullableInt($operation['fileSize'] ?? null),
            'shopify_completed_at' => filled($operation['completedAt'] ?? null) ? Carbon::parse($operation['completedAt']) : $run->shopify_completed_at,
            'metadata' => array_merge($run->metadata ?? [], [
                'operation_error_code' => $operation['errorCode'] ?? null,
                'operation_created_at' => $operation['createdAt'] ?? data_get($run->metadata, 'operation_created_at'),
                'operation_completed_at' => $operation['completedAt'] ?? data_get($run->metadata, 'operation_completed_at'),
            ]),
        ])->save();

        return $operation;
    }

    public function isTerminal(?string $status): bool
    {
        return in_array($status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
        ], true);
    }

    private function pollNode(string $operationId): array
    {
        $query = <<<'GQL'
query BulkOperationNode($id: ID!) {
  node(id: $id) {
    ... on BulkOperation {
      id
      status
      errorCode
      createdAt
      completedAt
      objectCount
      rootObjectCount
      fileSize
      url
      partialDataUrl
    }
  }
}
GQL;

        $data = $this->client->query($query, ['id' => $operationId]);

        $operation = data_get($data, 'node');
        if (!is_array($operation)) {
            throw new \RuntimeException("Shopify bulk operation {$operationId} could not be found.");
        }

        return $operation;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
