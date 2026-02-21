<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class ShopifyApiClient
{
    public function rest(string $method, string $path, array $payload = []): array
    {
        $shop = config('services.shopify.shop');
        $token = config('services.shopify.admin_access_token');
        $version = config('services.shopify.api_version', '2026-01');

        if (!$shop || !$token) {
            throw new \RuntimeException('Shopify API credentials are missing.');
        }

        $path = ltrim($path, '/');
        $url = "https://{$shop}/admin/api/{$version}/{$path}";
        $this->logOutgoing('rest', [
            'method' => strtoupper($method),
            'url' => $url,
            'path' => $path,
            'payload' => $payload,
        ]);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->send(strtoupper($method), $url, [
            'json' => $payload,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Shopify API request failed with status ' . $response->status() . '.');
        }

        return $response->json() ?? [];
    }

    public function graphql(string $query, array $variables = []): array
    {
        $shop = config('services.shopify.shop');
        $token = config('services.shopify.admin_access_token');
        $version = config('services.shopify.api_version', '2026-01');

        if (!$shop || !$token) {
            throw new \RuntimeException('Shopify API credentials are missing.');
        }

        $url = "https://{$shop}/admin/api/{$version}/graphql.json";
        $this->logOutgoing('graphql', [
            'url' => $url,
            'query' => $query,
            'variables' => $variables,
        ]);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Shopify API request failed with status ' . $response->status() . '.');
        }

        $payload = $response->json();
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $messages = collect($payload['errors'])->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException('Shopify API error: ' . ($messages !== '' ? $messages : 'Unknown error.'));
        }

        return $payload['data'] ?? [];
    }

    private function logOutgoing(string $kind, array $payload): void
    {
        logger()->info('Shopify API outgoing request', [
            'kind' => $kind,
            'payload' => $payload,
        ]);
    }
}
