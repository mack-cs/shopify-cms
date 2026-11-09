<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use RuntimeException;

final class ImageAltTextShopifyWriter
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function rejectionReason(string $mediaId): ?string
    {
        $mediaId = trim($mediaId);
        if ($mediaId === '') {
            return 'This image has no Shopify media id, so its alt text can be saved locally and retried after the media id is known.';
        }
        if (preg_match('~^gid://shopify/MediaImage/\d+$~', $mediaId) !== 1) {
            return 'Shopify alt text was not changed because '.$mediaId.' is not a MediaImage file id.';
        }

        return null;
    }

    public function update(string $mediaId, string $altText): void
    {
        $mediaId = trim($mediaId);
        $rejection = $this->rejectionReason($mediaId);
        if ($rejection !== null) {
            throw new RuntimeException($rejection);
        }

        $data = $this->shopify->graphql(<<<'GQL'
mutation UpdateImageAlt($files: [FileUpdateInput!]!) {
  fileUpdate(files: $files) {
    files { id alt }
    userErrors { field message }
  }
}
GQL, ['files' => [['id' => $mediaId, 'alt' => $altText]]]);

        $fileUpdate = is_array($data) ? ($data['fileUpdate'] ?? null) : null;
        if (! is_array($fileUpdate) || ! array_key_exists('userErrors', $fileUpdate) || ! is_array($fileUpdate['userErrors'])) {
            throw new RuntimeException('Shopify did not confirm the alt text update.');
        }

        $errors = $fileUpdate['userErrors'];
        if ($errors !== []) {
            $message = collect($errors)->map(fn ($error): string => trim((string) (is_array($error) ? ($error['message'] ?? '') : '')))
                ->filter()->implode(' ');
            throw new RuntimeException($message !== '' ? $message : 'Shopify rejected the alt text update.');
        }

        $files = $fileUpdate['files'] ?? null;
        $confirmed = is_array($files) && collect($files)->contains(fn ($file): bool => is_array($file)
            && ($file['id'] ?? null) === $mediaId
            && ($file['alt'] ?? null) === $altText);
        if (! $confirmed) {
            throw new RuntimeException('Shopify did not confirm the alt text update.');
        }
    }
}
