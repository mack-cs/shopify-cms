<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Product;

final class ProductLaunchShopify
{
    public function __construct(private readonly ShopifyGraphqlGateway $client) {}

    /** @return array{status:string,published_ids:array<int,string>} */
    public function read(Product $product): array
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
query LaunchProductState($id: ID!) {
  product(id: $id) {
    status
    resourcePublications(first: 50) {
      nodes { isPublished publication { id } }
    }
  }
}
GRAPHQL, ['id' => $this->productGid($product)]);
        $remote = data_get($data, 'product');
        if (! is_array($remote)) {
            throw new \RuntimeException('Shopify did not return the product, so it was not launched.');
        }
        $published = [];
        foreach ((array) data_get($remote, 'resourcePublications.nodes', []) as $node) {
            if (! is_array($node) || empty($node['isPublished'])) {
                continue;
            }
            $id = trim((string) data_get($node, 'publication.id'));
            if ($id !== '') {
                $published[] = $id;
            }
        }

        return [
            'status' => strtoupper(trim((string) ($remote['status'] ?? ''))),
            'published_ids' => $published,
        ];
    }

    public function activate(Product $product): string
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
mutation LaunchActivateProduct($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id status }
    userErrors { field message }
  }
}
GRAPHQL, ['input' => ['id' => $this->productGid($product), 'status' => 'ACTIVE']]);
        $this->guard('productUpdate', $data);
        $status = strtoupper(trim((string) data_get($data, 'productUpdate.product.status')));
        if ($status !== 'ACTIVE') {
            throw new \RuntimeException('Shopify did not confirm ACTIVE status.');
        }

        return $status;
    }

    /** @param array<int, string> $publicationIds */
    public function publish(Product $product, array $publicationIds): void
    {
        if ($publicationIds === []) {
            return;
        }
        $data = $this->client->graphql(<<<'GRAPHQL'
mutation LaunchPublishProduct($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    userErrors { field message }
  }
}
GRAPHQL, [
            'id' => $this->productGid($product),
            'input' => array_map(fn (string $id): array => ['publicationId' => $id], $publicationIds),
        ]);
        $this->guard('publishablePublish', $data);
    }

    public function publicationIsLive(Product $product, string $publicationId): bool
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
query LaunchConfirmPublication($id: ID!, $publication: ID!) {
  product(id: $id) { status publishedOnPublication(publicationId: $publication) }
}
GRAPHQL, ['id' => $this->productGid($product), 'publication' => $publicationId]);
        $status = strtoupper(trim((string) data_get($data, 'product.status')));
        if ($status !== 'ACTIVE' || data_get($data, 'product.publishedOnPublication') !== true) {
            return false;
        }

        return true;
    }

    public function productGid(Product $product): string
    {
        $id = trim((string) $product->shopify_id);
        if ($id === '') {
            throw new \RuntimeException('The product has no Shopify product ID.');
        }

        return str_starts_with($id, 'gid://shopify/') ? $id : 'gid://shopify/Product/'.$id;
    }

    /** @param array<string, mixed> $data */
    private function guard(string $mutation, array $data): void
    {
        $errors = data_get($data, $mutation.'.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException($message !== '' ? $message : 'Shopify rejected the launch operation.');
        }
    }
}
