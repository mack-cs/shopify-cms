<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use RuntimeException;

final class ImageAltTextShopifyWriter
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function rejectionReason(string $mediaId): ?string
    {
        return preg_match('~^gid://shopify/MediaImage/\d+$~', trim($mediaId)) === 1
            ? null : 'Shopify alt text requires a verified MediaImage file id.';
    }

    public function update(string $mediaId, string $altText, ?string $productGid = null, bool $confirmationOnly = false, ?callable $assertOwned = null): void
    {
        if (($reason = $this->rejectionReason($mediaId)) !== null || ! $productGid) {
            throw new RuntimeException($reason ?? 'The product is required for attached media verification.');
        }
        $node = $this->attachedNode($productGid, $mediaId, 'ReadAttachedImageAlt');
        if ($node === null || ! ShopifyProductMediaReader::ready($node)) {
            if ($confirmationOnly) {
                throw new AltTextAwaitingConfirmation('The previously updated file is not ready for confirmation.');
            }
            throw new AltTextRejected('The current attached Shopify file is missing or not READY.');
        }
        if (($node['alt'] ?? null) === $altText) {
            return;
        }
        if (! $confirmationOnly) {
            $assertOwned && $assertOwned();
            try {
                $data = $this->shopify->graphql(<<<'GQL'
mutation UpdateImageAlt($files: [FileUpdateInput!]!) {
  fileUpdate(files: $files) { files { id alt } userErrors { field message } }
}
GQL, ['files' => [['id' => $mediaId, 'alt' => $altText]]]);
            } catch (\Throwable $exception) {
                throw new AltTextAwaitingConfirmation('The update outcome is uncertain; reconcile before resending.', previous: $exception);
            }
            $payload = $data['fileUpdate'] ?? null;
            if (! is_array($payload) || ! is_array($payload['userErrors'] ?? null)) {
                throw new AltTextAwaitingConfirmation('Shopify did not return a definitive update result.');
            }
            if ($payload['userErrors'] !== []) {
                throw new AltTextRejected(collect($payload['userErrors'])->pluck('message')->implode(' '));
            }
            // The mutation response is not publication evidence. Poll the product.
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                $delay = app()->environment('testing') ? 0 : 500000 * $attempt;
                usleep($delay);
            }
            try {
                $node = $this->attachedNode($productGid, $mediaId, 'ConfirmAttachedImageAlt');
                $assertOwned && $assertOwned();
                if ($node !== null && ShopifyProductMediaReader::ready($node) && ($node['alt'] ?? null) === $altText) {
                    return;
                }
            } catch (\Throwable) {
                // A read failure cannot prove a mutation failed.
            }
        }
        throw new AltTextAwaitingConfirmation('Awaiting confirmation: a fresh read did not show the approved alt text on ready attached media. No update will be replayed automatically.');
    }

    public function attachedAlt(string $productGid, string $mediaId): ?string
    {
        $node = $this->attachedNode($productGid, $mediaId, 'ReadAttachedImageAlt');

        return $node !== null && ShopifyProductMediaReader::ready($node) ? (string) ($node['alt'] ?? '') : null;
    }

    private function attachedNode(string $productGid, string $mediaId, string $queryName): ?array
    {
        return collect((new ShopifyProductMediaReader($this->shopify))->media($productGid, $queryName))
            ->first(fn (array $node): bool => ($node['id'] ?? '') === $mediaId);
    }
}
