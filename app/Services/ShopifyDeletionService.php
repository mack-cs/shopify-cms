<?php

namespace App\Services;

final class ShopifyDeletionService
{
    public function __construct(
        private readonly ShopifyApiClient $client,
    ) {}

    public function deleteProduct(string $shopifyId): void
    {
        $data = $this->client->graphql($this->productDeleteMutation(), [
            'input' => [
                'id' => $shopifyId,
            ],
        ]);

        $errors = data_get($data, 'productDelete.userErrors', []);
        if ($this->isIgnorableMissingResourceError($errors)) {
            return;
        }

        if (is_array($errors) && !empty($errors)) {
            $messages = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException($messages !== '' ? $messages : 'Failed to delete product in Shopify.');
        }
    }

    public function deleteCollection(string $shopifyId): void
    {
        $data = $this->client->graphql($this->collectionDeleteMutation(), [
            'input' => [
                'id' => $shopifyId,
            ],
        ]);

        $errors = data_get($data, 'collectionDelete.userErrors', []);
        if ($this->isIgnorableMissingResourceError($errors)) {
            return;
        }

        if (is_array($errors) && !empty($errors)) {
            $messages = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException($messages !== '' ? $messages : 'Failed to delete collection in Shopify.');
        }
    }

    private function productDeleteMutation(): string
    {
        return <<<'GQL'
mutation ProductDelete($input: ProductDeleteInput!) {
  productDelete(input: $input) {
    deletedProductId
    userErrors { field message }
  }
}
GQL;
    }

    private function collectionDeleteMutation(): string
    {
        return <<<'GQL'
mutation CollectionDelete($input: CollectionDeleteInput!) {
  collectionDelete(input: $input) {
    deletedCollectionId
    userErrors { field message }
  }
}
GQL;
    }

    /**
     * @param mixed $errors
     */
    private function isIgnorableMissingResourceError(mixed $errors): bool
    {
        if (!is_array($errors) || $errors === []) {
            return false;
        }

        $messages = collect($errors)
            ->pluck('message')
            ->filter(fn ($message) => is_string($message) && trim($message) !== '')
            ->map(fn (string $message): string => strtolower(trim($message)));

        return $messages->isNotEmpty()
            && $messages->every(fn (string $message): bool => str_contains($message, 'does not exist') || str_contains($message, 'not found'));
    }
}
