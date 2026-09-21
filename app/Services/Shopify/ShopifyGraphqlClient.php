<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;

final class ShopifyGraphqlClient
{
    public function __construct(
        private readonly ShopifySyncTokenResolver $tokenResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $shop = $this->tokenResolver->shop();
        $token = $this->tokenResolver->token();
        $version = config('services.shopify.api_version', '2026-01');
        $variablesPayload = empty($variables) ? (object) [] : $variables;
        $url = "https://{$shop}/admin/api/{$version}/graphql.json";

        logger()->info('Shopify sync GraphQL request', [
            'url' => $url,
            'operation' => $this->operationName($query),
            'variables' => array_keys($variables),
        ]);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $query,
            'variables' => $variablesPayload,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Shopify sync GraphQL request failed with status ' . $response->status() . '.');
        }

        $payload = $response->json();
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $messages = collect($payload['errors'])->pluck('message')->filter()->implode('; ');
            throw new \RuntimeException('Shopify sync GraphQL error: ' . ($messages !== '' ? $messages : 'Unknown error.'));
        }

        return $payload['data'] ?? [];
    }

    private function operationName(string $query): ?string
    {
        if (preg_match('/\b(query|mutation)\s+([A-Za-z0-9_]+)/', $query, $matches)) {
            return $matches[2];
        }

        return null;
    }
}
