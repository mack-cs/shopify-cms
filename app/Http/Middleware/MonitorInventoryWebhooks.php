<?php

namespace App\Http\Middleware;

use App\Services\Shopify\InventoryPipelineNotifications;
use Closure;
use Illuminate\Http\Request;

final class MonitorInventoryWebhooks
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('inventory_monitor.enabled')) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $this->observe($request, true, $error->getMessage());
            throw $error;
        }
        // Authentication and validation rejections are not operational failures.
        if ($response->getStatusCode() >= 500 || $response->getStatusCode() < 300) {
            $this->observe($request, $response->getStatusCode() >= 500, 'HTTP '.$response->getStatusCode());
        }

        return $response;
    }

    private function observe(Request $request, bool $failed, string $message): void
    {
        try {
            $payload = json_decode($request->getContent(), true) ?: [];
            $topic = (string) $request->header('X-Shopify-Topic');
            $fulfillment = str_starts_with($topic, 'fulfillments/');
            $orderId = $fulfillment ? ($payload['order_id'] ?? null) : ($payload['admin_graphql_api_id'] ?? $payload['id'] ?? null);
            $identity = $request->path().'|'.($orderId ?? 'unknown');
            app(InventoryPipelineNotifications::class)->observe('webhook_processing_failed', 'webhook', $identity, $failed, [
                'order_id' => $orderId, 'order_number' => $payload['name'] ?? null,
                'fulfillment_id' => $fulfillment ? ($payload['admin_graphql_api_id'] ?? $payload['id'] ?? null) : null,
                'error_message' => $message, 'status' => $failed ? 'webhook_failed' : 'webhook_accepted', 'topic' => $topic,
            ]);
        } catch (\Throwable $error) {
            logger()->error('Inventory webhook monitor could not persist observation.', ['error' => $error->getMessage()]);
        }
    }
}
