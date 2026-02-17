<?php

namespace App\Services;

use App\Models\NewProductDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class NewProductDraftShopifyCreator
{
    public function __construct(
        private readonly ShopifyApiClient $client,
    ) {}

    /**
     * @return array{
     *   created:int,
     *   skipped_not_approved:int,
     *   skipped_has_handle:int,
     *   failed:int,
     *   failures: array<int, array{
     *     id:int|null,
     *     title:string|null,
     *     reason:string,
     *     details:string|null
     *   }>
     *   ,warnings: array<int, array{
     *     id:int|null,
     *     title:string|null,
     *     reason:string,
     *     details:string|null
     *   }>
     * }
     */
    public function createApprovedDrafts(Collection $drafts): array
    {
        $created = 0;
        $skippedNotApproved = 0;
        $skippedHasHandle = 0;
        $failed = 0;
        $failures = [];
        $warnings = [];

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            if ($draft->handle) {
                $skippedHasHandle++;
                continue;
            }

            if (!$draft->isApprovedByTwo()) {
                $skippedNotApproved++;
                continue;
            }

            try {
                $result = $this->createProduct($draft);
                if (!$result['handle'] || !$result['id']) {
                    $failed++;
                    $failures[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => 'shopify_user_error',
                        'details' => $result['error'],
                    ];
                    continue;
                }

                if ($result['media_error']) {
                    $warnings[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => 'media_failed',
                        'details' => $result['media_error'],
                    ];
                }

                $draft->update([
                    'handle' => $result['handle'],
                    'shopify_id' => $result['id'],
                ]);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'id' => $draft->id,
                    'title' => $draft->title,
                    'reason' => 'exception',
                    'details' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'skipped_not_approved' => $skippedNotApproved,
            'skipped_has_handle' => $skippedHasHandle,
            'failed' => $failed,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{id:?string, handle:?string, error:?string, media_error:?string}
     */
    private function createProduct(NewProductDraft $draft): array
    {
        $input = [
            'title' => $draft->title,
            'status' => $this->mapStatus($draft->status ?? 'draft'),
        ];

        if ($draft->body_html) {
            $input['descriptionHtml'] = $draft->body_html;
        }
        if ($draft->vendor) {
            $input['vendor'] = $draft->vendor;
        }
        // Product category is stored but not sent here to avoid API errors
        // when a non-GID value is provided.

        // NOTE: Shopify ProductInput does not accept variants in this API version.
        // Variants must be created in a follow-up mutation after productCreate.

        $data = $this->client->graphql($this->mutation(), ['input' => $input]);
        $payload = $data['productCreate'] ?? null;
        if (!$payload) {
            return [
                'id' => null,
                'handle' => null,
                'error' => 'Missing productCreate payload.',
                'media_error' => null,
            ];
        }

        $errors = $payload['userErrors'] ?? [];
        if (!empty($errors)) {
            $messages = collect($errors)
                ->map(function (array $error): string {
                    $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'input';
                    $message = $error['message'] ?? 'Unknown error';
                    return "{$field}: {$message}";
                })
                ->implode('; ');

            return [
                'id' => null,
                'handle' => null,
                'error' => $messages !== '' ? $messages : 'Unknown user error.',
                'media_error' => null,
            ];
        }

        $product = $payload['product'] ?? null;
        $mediaError = null;

        if ($product && $draft->image_path) {
            $mediaError = $this->attachPrimaryImage($product['id'] ?? null, $draft->image_path);
        }

        return [
            'id' => $product['id'] ?? null,
            'handle' => $product['handle'] ?? null,
            'error' => null,
            'media_error' => $mediaError,
        ];
    }

    private function attachPrimaryImage(?string $productId, string $imagePath): ?string
    {
        if (!$productId) {
            return 'Missing product id for media upload.';
        }

        $url = Storage::disk('public')->url($imagePath);
        if (!$url) {
            return 'Unable to resolve image URL.';
        }

        $data = $this->client->graphql($this->mediaMutation(), [
            'productId' => $productId,
            'media' => [
                [
                    'originalSource' => $url,
                    'mediaContentType' => 'IMAGE',
                ],
            ],
        ]);

        $payload = $data['productCreateMedia'] ?? null;
        if (!$payload) {
            return 'Missing productCreateMedia payload.';
        }

        $errors = $payload['mediaUserErrors'] ?? [];
        if (!empty($errors)) {
            $messages = collect($errors)
                ->map(function (array $error): string {
                    $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'media';
                    $message = $error['message'] ?? 'Unknown error';
                    return "{$field}: {$message}";
                })
                ->implode('; ');
            return $messages !== '' ? $messages : 'Unknown media error.';
        }

        return null;
    }

    private function mutation(): string
    {
        return <<<'GQL'
mutation ProductCreate($input: ProductInput!) {
  productCreate(input: $input) {
    product {
      id
      handle
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function mediaMutation(): string
    {
        return <<<'GQL'
mutation ProductCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media {
      ... on MediaImage {
        id
      }
    }
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function mapStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'active' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            default => 'DRAFT',
        };
    }

    private function mapInventoryPolicy(string $policy): string
    {
        $normalized = strtolower(trim($policy));
        return $normalized === 'continue' ? 'CONTINUE' : 'DENY';
    }
}
