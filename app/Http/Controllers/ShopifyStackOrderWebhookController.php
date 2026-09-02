<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyStackOrderEventJob;
use App\Models\ShopifyStackOrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShopifyStackOrderWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->shouldVerifyWebhook() && ! $this->hasValidHmac($request)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $topic = strtolower(trim((string) $request->header('X-Shopify-Topic')));
        if (! in_array($topic, ['orders/create', 'orders/updated', 'orders/cancelled'], true)) {
            return response()->json(['message' => 'Unsupported webhook topic.'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        $orderId = trim((string) ($payload['admin_graphql_api_id'] ?? $payload['id'] ?? ''));
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id'));
        if (! is_array($payload) || $orderId === '' || $webhookId === '') {
            return response()->json(['message' => 'Invalid order webhook payload.'], 422);
        }

        $event = ShopifyStackOrderEvent::query()->firstOrCreate(
            ['webhook_id' => $webhookId],
            [
                'topic' => $topic,
                'shopify_order_id' => $this->gid('Order', $orderId),
                'shopify_order_name' => trim((string) ($payload['name'] ?? '')) ?: null,
                'shopify_updated_at' => filled($payload['updated_at'] ?? null) ? $payload['updated_at'] : null,
                'payload' => $payload,
                'status' => ShopifyStackOrderEvent::STATUS_PENDING,
            ],
        );

        if ($event->wasRecentlyCreated || $event->status === ShopifyStackOrderEvent::STATUS_FAILED) {
            ProcessShopifyStackOrderEventJob::dispatch($event->id);
        }

        return response()->json(['status' => $event->wasRecentlyCreated ? 'queued' : 'duplicate'], $event->wasRecentlyCreated ? 202 : 200);
    }

    private function hasValidHmac(Request $request): bool
    {
        $secret = trim((string) config('services.shopify.webhook_secret'));
        $header = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        if ($secret === '' || $header === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $header);
    }

    private function shouldVerifyWebhook(): bool
    {
        return (bool) config('services.shopify.verify_webhooks', true) || ! app()->environment(['local', 'testing']);
    }

    private function gid(string $type, string $id): string
    {
        return str_starts_with($id, 'gid://shopify/') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
