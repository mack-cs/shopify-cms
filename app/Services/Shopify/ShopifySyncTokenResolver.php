<?php

namespace App\Services\Shopify;

use App\Services\AwsSecretService;
use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Cache;

final class ShopifySyncTokenResolver
{
    private ?SecretsManagerClient $client = null;

    public function __construct(
        private readonly AwsSecretService $defaultSecretService,
    ) {
    }

    public function shop(): string
    {
        $shop = trim((string) config('shopify_sync.shopify.shop', ''));
        if ($shop === '') {
            throw new \RuntimeException('SHOPIFY_SYNC_SHOP or SHOPIFY_SHOP is not configured.');
        }

        return $shop;
    }

    public function token(): string
    {
        $envToken = trim((string) config('shopify_sync.shopify.admin_access_token', ''));
        if ($envToken !== '') {
            return $envToken;
        }

        $secretId = trim((string) config('shopify_sync.shopify.secret_id', ''));
        if ($secretId !== '') {
            return $this->tokenFromSecret($secretId);
        }

        if ((bool) config('shopify_sync.shopify.fallback_to_default_token', true)) {
            return $this->defaultSecretService->getShopifyToken();
        }

        throw new \RuntimeException('Shopify sync token is missing. Set SHOPIFY_SYNC_ADMIN_ACCESS_TOKEN or AWS_SHOPIFY_SYNC_SECRET_ID.');
    }

    private function tokenFromSecret(string $secretId): string
    {
        $cacheKey = trim((string) config('shopify_sync.shopify.secret_cache_key', 'shopify_sync.admin_access_token'));
        $ttlSeconds = max(60, (int) config('shopify_sync.shopify.secret_cache_ttl', 900));

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($secretId): string {
            $result = $this->client()->getSecretValue([
                'SecretId' => $secretId,
            ]);

            $secretString = (string) ($result['SecretString'] ?? '');
            if ($secretString === '') {
                throw new \RuntimeException('AWS Shopify sync secret is missing SecretString.');
            }

            $decoded = json_decode($secretString, true);
            if (is_array($decoded)) {
                foreach ([
                    'SHOPIFY_SYNC_ADMIN_ACCESS_TOKEN',
                    'SHOPIFY_ADMIN_ACCESS_TOKEN',
                    'SHOPIFY_ACCESS_TOKEN',
                    'shopify_sync_admin_access_token',
                    'shopify_admin_access_token',
                    'shopify_access_token',
                    'access_token',
                ] as $key) {
                    $token = trim((string) ($decoded[$key] ?? ''));
                    if ($token !== '') {
                        return $token;
                    }
                }
            }

            $secretString = trim($secretString);
            if ($secretString === '') {
                throw new \RuntimeException('Shopify sync token was not found in AWS secret.');
            }

            return $secretString;
        });
    }

    private function client(): SecretsManagerClient
    {
        if ($this->client instanceof SecretsManagerClient) {
            return $this->client;
        }

        $config = [
            'version' => (string) config('shopify_sync.shopify.secret_version', 'latest'),
            'region' => (string) config('shopify_sync.shopify.secret_region', 'us-east-1'),
        ];

        $profile = trim((string) config('shopify_sync.shopify.secret_profile', ''));
        if ($profile !== '') {
            $config['profile'] = $profile;
        }

        return $this->client = new SecretsManagerClient($config);
    }
}
