<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use RuntimeException;

/**
 * Reads a product's current media and returns one verified MediaImage id.
 * It never sends a Shopify mutation.
 */
final class ShopifyMediaImageResolver
{
    /** @var array<string, array<string, mixed>> */
    private array $products = [];

    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function resolve(Image $image): string
    {
        $productGid = trim((string) ($image->product?->shopify_id ?? ''));
        if ($productGid === '') {
            throw new RuntimeException('Image '.$image->id.' has no Shopify product, so its alt text stayed in the CMS.');
        }

        $product = $this->product($productGid, (int) $image->id);
        $media = collect(is_array($product['media']['nodes'] ?? null) ? $product['media']['nodes'] : [])
            ->filter(fn ($node): bool => is_array($node) && preg_match('~^gid://shopify/MediaImage/\d+$~', (string) ($node['id'] ?? '')) === 1)
            ->values();
        $localId = trim((string) $image->shopify_id);

        if (preg_match('~^gid://shopify/MediaImage/\d+$~', $localId) === 1) {
            if (! $media->contains(fn (array $node): bool => $node['id'] === $localId)) {
                throw new RuntimeException('Shopify alt text was not changed because '.$localId.' is not a media file on this product.');
            }

            return $localId;
        }

        $url = '';
        if (preg_match('~^gid://shopify/ProductImage/\d+$~', $localId) === 1) {
            $legacy = collect(is_array($product['images']['nodes'] ?? null) ? $product['images']['nodes'] : [])
                ->first(fn ($node): bool => is_array($node) && (string) ($node['id'] ?? '') === $localId);
            $url = is_array($legacy) ? trim((string) ($legacy['url'] ?? '')) : '';
        }
        if ($url === '') {
            $url = trim((string) $image->src);
        }

        $key = $this->photographKey($url);
        if ($key === null) {
            throw new RuntimeException('Image '.$image->id.' has no MediaImage id and no photograph URL that can be matched.');
        }

        $matches = $media->filter(fn (array $node): bool => $this->photographKey((string) ($node['image']['url'] ?? '')) === $key)->values();
        if ($matches->count() !== 1) {
            throw new RuntimeException('Image '.$image->id.' matches '.$matches->count().' Shopify media files. Alt text was not changed.');
        }

        $resolved = (string) $matches[0]['id'];
        if ($resolved !== $localId) {
            Image::withoutEvents(function () use ($image, $resolved): void {
                $image->forceFill(['shopify_id' => $resolved])->save();
            });
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $productGid, int $imageId): array
    {
        if (isset($this->products[$productGid])) {
            return $this->products[$productGid];
        }

        $data = $this->shopify->graphql(<<<'GQL'
query ProductAltMedia($id: ID!) {
  product(id: $id) {
    media(first: 250) {
      nodes {
        id
        ... on MediaImage { image { url } }
      }
    }
    images(first: 250) { nodes { id url } }
  }
}
GQL, ['id' => $productGid]);

        $product = is_array($data) ? ($data['product'] ?? null) : null;
        if (! is_array($product)) {
            throw new RuntimeException('Shopify did not return media for image '.$imageId.'.');
        }

        return $this->products[$productGid] = $product;
    }

    private function photographKey(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        return strtolower((string) $parts['host']).$parts['path'];
    }
}
