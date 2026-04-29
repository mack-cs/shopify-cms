<?php

namespace App\Services;

use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Cache;

class AwsSecretService
{
    private ?SecretsManagerClient $client = null;

    public function getShopifyToken(): string
    {
        $envToken = trim((string) config('services.shopify.admin_access_token', ''));
        if ($envToken !== '') {
            return $envToken;
        }

        $cacheKey = trim((string) config('services.shopify.secret_cache_key', 'shopify.admin_access_token'));
        $ttlSeconds = max(60, (int) config('services.shopify.secret_cache_ttl', 900));

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function (): string {
            $result = $this->client()->getSecretValue([
                'SecretId' => $this->shopifySecretId(),
            ]);

            $secretString = (string) ($result['SecretString'] ?? '');
            if ($secretString === '') {
                throw new \RuntimeException('AWS secret is missing SecretString.');
            }

            $decoded = json_decode($secretString, true);
            if (is_array($decoded)) {
                $token = trim((string) ($decoded['SHOPIFY_ACCESS_TOKEN'] ?? $decoded['shopify_access_token'] ?? ''));
                if ($token !== '') {
                    return $token;
                }
            }

            $secretString = trim($secretString);
            if ($secretString === '') {
                throw new \RuntimeException('Shopify token was not found in AWS secret.');
            }

            return $secretString;
        });
    }

    public function hasShopifyToken(): bool
    {
        try {
            return trim($this->getShopifyToken()) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(): SecretsManagerClient
    {
        if ($this->client instanceof SecretsManagerClient) {
            return $this->client;
        }

        $config = [
            'version' => (string) config('services.shopify.secret_version', 'latest'),
            'region' => (string) config('services.shopify.secret_region', 'us-east-1'),
        ];

        $profile = trim((string) config('services.shopify.secret_profile', ''));
        if ($profile !== '') {
            $config['profile'] = $profile;
        }

        return $this->client = new SecretsManagerClient($config);
    }

    private function shopifySecretId(): string
    {
        $secretId = trim((string) config('services.shopify.secret_id', 'prod/leighavenue/shopify'));
        if ($secretId === '') {
            throw new \RuntimeException('AWS_SHOPIFY_SECRET_ID is not configured.');
        }

        return $secretId;
    }
}
