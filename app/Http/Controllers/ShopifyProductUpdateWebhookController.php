<?php

namespace App\Http\Controllers;

use App\Jobs\HandleShopifyProductUpdatedJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyProductUpdateWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->shouldVerifyWebhook()) {
            $secret = trim((string) config('services.shopify.webhook_secret'));
            if ($secret === '') {
                Log::error('Shopify product webhook rejected because SHOPIFY_WEBHOOK_SECRET is not configured.');

                return response()->json(['message' => 'Webhook secret is not configured.'], 500);
            }

            if (!$this->hasValidHmac($request, $secret)) {
                $this->logInvalidHmac($request, $secret);

                return response()->json(['message' => 'Invalid webhook signature.'], 401);
            }
        } else {
            Log::warning('Shopify product webhook HMAC verification skipped for local testing.', [
                'environment' => app()->environment(),
                'topic' => $request->header('X-Shopify-Topic'),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            ]);
        }

        $topic = strtolower(trim((string) $request->header('X-Shopify-Topic', '')));
        if ($topic !== '' && $topic !== 'products/update') {
            Log::warning('Shopify product webhook rejected because topic is unsupported.', [
                'topic' => $topic,
                'expected_topic' => 'products/update',
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
            ]);

            return response()->json(['message' => 'Unsupported webhook topic.'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            Log::warning('Shopify product webhook rejected because payload is not valid JSON.', [
                'topic' => $topic,
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                'body_length' => strlen($request->getContent()),
            ]);

            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $shopifyProductId = $this->shopifyProductIdFromPayload($payload);
        $handle = trim((string) ($payload['handle'] ?? ''));

        if ($shopifyProductId === '' && $handle === '') {
            Log::warning('Shopify product webhook rejected because product identity is missing.', [
                'topic' => $topic,
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                'payload_keys' => array_keys($payload),
            ]);

            return response()->json(['message' => 'Missing product identity.'], 422);
        }

        HandleShopifyProductUpdatedJob::dispatch(
            $shopifyProductId,
            $handle,
            $payload,
            trim((string) $request->header('X-Shopify-Webhook-Id')) ?: null,
        );

        Log::info('Shopify product webhook accepted and queued.', [
            'topic' => $topic,
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
            'shopify_product_id' => $shopifyProductId,
            'handle' => $handle,
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shopifyProductIdFromPayload(array $payload): string
    {
        $gid = trim((string) ($payload['admin_graphql_api_id'] ?? ''));
        if ($gid !== '') {
            return $gid;
        }

        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return '';
        }

        return preg_match('/^\d+$/', $id) === 1
            ? 'gid://shopify/Product/' . $id
            : $id;
    }

    private function hasValidHmac(Request $request, string $secret): bool
    {
        $header = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        if ($header === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($calculated, $header);
    }

    private function shouldVerifyWebhook(): bool
    {
        if ((bool) config('services.shopify.verify_webhooks', true)) {
            return true;
        }

        return !app()->environment(['local', 'testing']);
    }

    private function logInvalidHmac(Request $request, string $secret): void
    {
        $header = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        Log::warning('Shopify product webhook rejected because HMAC signature did not match.', [
            'environment' => app()->environment(),
            'verify_webhooks' => config('services.shopify.verify_webhooks'),
            'topic' => $request->header('X-Shopify-Topic'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
            'hmac_header_present' => $header !== '',
            'hmac_header_length' => strlen($header),
            'calculated_hmac_length' => strlen($calculated),
            'secret_length' => strlen($secret),
            'body_length' => strlen($request->getContent()),
        ]);
    }
}
