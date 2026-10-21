<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use Illuminate\Support\Facades\Http;

final class ShopifyApiClient implements ShopifyGraphqlGateway
{
    public function __construct(
        private readonly AwsSecretService $awsSecretService,
    ) {}

    public function rest(string $method, string $path, array $payload = []): array
    {
        $shop = config('services.shopify.shop');
        $token = $this->awsSecretService->getShopifyToken();
        $version = config('services.shopify.api_version', '2026-01');

        if (! $shop || ! $token) {
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

        if (! $response->successful()) {
            throw new \RuntimeException('Shopify API request failed with status '.$response->status().'.');
        }

        return $response->json() ?? [];
    }

    public function graphql(string $query, array $variables = []): array
    {
        $shop = config('services.shopify.shop');
        $token = $this->awsSecretService->getShopifyToken();
        $version = config('services.shopify.api_version', '2026-01');

        if (! $shop || ! $token) {
            throw new \RuntimeException('Shopify API credentials are missing.');
        }

        $variablesPayload = empty($variables) ? (object) [] : $variables;

        $url = "https://{$shop}/admin/api/{$version}/graphql.json";
        $this->logOutgoing('graphql', [
            'url' => $url,
            'query' => $query,
            'variables' => $variablesPayload,
        ]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout(10)
                ->timeout(60)
                ->post($url, [
                    'query' => $query,
                    'variables' => $variablesPayload,
                ]);

            $payload = $this->discardFalseThrottleErrors($response->json() ?? []);
            if ($this->isThrottledResponse($response->status(), $payload) && $attempt < 4) {
                $delay = $this->throttleDelayMilliseconds($payload, $attempt);
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter !== null) {
                    $retrySeconds = is_numeric($retryAfter)
                        ? (float) $retryAfter
                        : max(0, (strtotime($retryAfter) ?: time()) - time());
                    $delay = max($delay, (int) ceil($retrySeconds * 1000));
                }
                logger()->warning('Shopify GraphQL request throttled; retrying', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'cost' => data_get($payload, 'extensions.cost'),
                ]);
                usleep($delay * 1000);

                continue;
            }

            if ($this->isThrottledResponse($response->status(), $payload)) {
                logger()->error('Shopify GraphQL request remained throttled after retries', [
                    'attempt' => $attempt,
                    'cost' => data_get($payload, 'extensions.cost'),
                ]);
                throw new \RuntimeException('Shopify is rate limiting requests. Some changes may still be pending; please retry shortly.');
            }

            if (! $response->successful()) {
                throw new \RuntimeException('Shopify API request failed with status '.$response->status().'.');
            }

            if (isset($payload['errors']) && is_array($payload['errors'])) {
                $messages = collect($payload['errors'])->pluck('message')->filter()->implode('; ');
                throw new \RuntimeException('Shopify API error: '.($messages !== '' ? $messages : 'Unknown error.'));
            }

            return $payload['data'] ?? [];
        }

        throw new \RuntimeException('Shopify GraphQL request remained throttled after four attempts.');
    }

    /**
     * Shopify can attach a THROTTLED error to a query that already ran while the
     * bucket is still full. Retrying that response returns the same body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function discardFalseThrottleErrors(array $payload): array
    {
        $errors = $payload['errors'] ?? null;
        if (! is_array($errors) || $errors === [] || ! array_key_exists('data', $payload)) {
            return $payload;
        }

        $requested = (float) data_get($payload, 'extensions.cost.requestedQueryCost', 0);
        $available = (float) data_get($payload, 'extensions.cost.throttleStatus.currentlyAvailable', 0);
        $actual = data_get($payload, 'extensions.cost.actualQueryCost');
        $ran = $actual !== null && $actual !== '';
        if (! $ran || $available < max($requested, 1)) {
            return $payload;
        }

        $remaining = array_values(array_filter(
            $errors,
            fn (mixed $error): bool => ! $this->errorIsThrottle($error),
        ));
        if (count($remaining) === count($errors)) {
            return $payload;
        }

        logger()->warning('Shopify returned a throttle flag after the query ran with capacity remaining. The result was kept.', [
            'cost' => data_get($payload, 'extensions.cost'),
        ]);
        if ($remaining === []) {
            unset($payload['errors']);
        } else {
            $payload['errors'] = $remaining;
        }

        return $payload;
    }

    private function isThrottledResponse(int $status, array $payload): bool
    {
        if ($status === 429) {
            return true;
        }

        return collect($payload['errors'] ?? [])->contains(fn (mixed $error): bool => $this->errorIsThrottle($error));
    }

    private function errorIsThrottle(mixed $error): bool
    {
        return strtoupper((string) data_get($error, 'extensions.code')) === 'THROTTLED'
            || str_contains(strtolower((string) data_get($error, 'message')), 'throttl');
    }

    private function throttleDelayMilliseconds(array $payload, int $attempt): int
    {
        $requested = (float) data_get($payload, 'extensions.cost.requestedQueryCost', 0);
        $available = (float) data_get($payload, 'extensions.cost.throttleStatus.currentlyAvailable', 0);
        $restoreRate = (float) data_get($payload, 'extensions.cost.throttleStatus.restoreRate', 0);

        if ($restoreRate > 0 && $requested > $available) {
            return max(250, (int) ceil((($requested - $available) / $restoreRate) * 1000) + 100);
        }

        return 500 * (2 ** ($attempt - 1));
    }

    private function logOutgoing(string $kind, array $payload): void
    {
        logger()->info('Shopify API outgoing request', [
            'kind' => $kind,
            'payload' => $payload,
        ]);
    }
}
