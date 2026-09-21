<?php

use App\Services\ShopifyApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set(['services.shopify.shop' => 'vibe-test.myshopify.com', 'services.shopify.admin_access_token' => 'test-token']);
});

it('retries a Shopify HTTP throttle and respects Retry-After', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['errors' => [['message' => 'Throttled']]], 429, ['Retry-After' => '1'])
        ->push(['data' => ['ok' => true]])]);
    $start = microtime(true);
    expect(app(ShopifyApiClient::class)->graphql('query VibeRetry { shop { name } }'))->toBe(['ok' => true]);
    expect(microtime(true) - $start)->toBeGreaterThanOrEqual(1.0);
    Http::assertSentCount(2);
});

it('retries GraphQL cost throttling and reports exhausted throttles as failures', function () {
    Log::spy();
    $throttle = ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
        'extensions' => ['cost' => ['requestedQueryCost' => 1, 'throttleStatus' => ['currentlyAvailable' => 0, 'restoreRate' => 1000]]]];
    Http::fake(['*' => Http::sequence()->push($throttle)->push($throttle)->push($throttle)->push($throttle)]);
    expect(fn () => app(ShopifyApiClient::class)->graphql('query VibeRetry { shop { name } }'))
        ->toThrow(RuntimeException::class, 'rate limiting');
    Http::assertSentCount(4);
    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('error')->once();
});
