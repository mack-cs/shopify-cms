<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class ShopifyFulfillmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->shouldVerifyWebhook()) {
            $secret = trim((string) config('services.shopify.webhook_secret'));
            if ($secret === '') {
                return response()->json(['message' => 'Webhook secret is not configured.'], 500);
            }
            if (! $this->hasValidHmac($request, $secret)) {
                return response()->json(['message' => 'Invalid webhook signature.'], 401);
            }
        }

        $topic = strtolower(trim((string) $request->header('X-Shopify-Topic', '')));
        if ($topic !== '' && ! in_array($topic, ['fulfillments/create', 'fulfillments/update'], true)) {
            return response()->json(['message' => 'Unsupported webhook topic.'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $fulfillmentId = trim((string) ($payload['admin_graphql_api_id'] ?? $payload['id'] ?? ''));
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        if ($fulfillmentId === '' || $orderId === '') {
            return response()->json(['message' => 'Missing fulfillment or order ID.'], 422);
        }

        $order = ShopifyOrder::query()
            ->whereIn('shopify_order_id', [$orderId, $this->gid('Order', $orderId)])
            ->first();
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id')) ?: null;

        $fulfillment = ShopifyFulfillment::query()->updateOrCreate(
            ['shopify_fulfillment_id' => $fulfillmentId],
            [
                'shopify_order_id' => $orderId,
                'shopify_order_db_id' => $order?->id,
                'shopify_location_id' => trim((string) ($payload['location_id'] ?? '')) ?: null,
                'shopify_status' => strtolower(trim((string) ($payload['status'] ?? ''))),
                'webhook_id' => $webhookId,
                'processing_status' => ShopifyFulfillment::STATUS_PENDING,
                'payload' => $payload,
                'fulfilled_at_shopify' => $this->date($payload['created_at'] ?? null),
                'error_message' => null,
            ],
        );

        ProcessShopifyStackFulfillmentJob::dispatch($fulfillment->id);

        Log::info('Shopify fulfillment webhook accepted and queued.', [
            'topic' => $topic,
            'webhook_id' => $webhookId,
            'shopify_fulfillment_id' => $fulfillmentId,
            'shopify_order_id' => $orderId,
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    private function hasValidHmac(Request $request, string $secret): bool
    {
        $header = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return $header !== '' && hash_equals($calculated, $header);
    }

    private function shouldVerifyWebhook(): bool
    {
        return (bool) config('services.shopify.verify_webhooks', true)
            || ! app()->environment(['local', 'testing']);
    }

    private function gid(string $type, string $id): string
    {
        return str_starts_with($id, 'gid://shopify/') ? $id : "gid://shopify/{$type}/{$id}";
    }

    private function date(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? Carbon::parse($value) : null;
    }
}
