<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Services\ImagePhotographEvidence;
use App\Services\ShopifyProductMediaReader;
use Illuminate\Console\Command;
use RuntimeException;

final class VerifyShopifyPhotograph extends Command
{
    protected $signature = 'images:verify-shopify-photograph {image} {--media-id=}';

    protected $description = 'Read-only verification of one current attached Shopify image URL and original SHA-256; never repairs or publishes';

    public function handle(ShopifyProductMediaReader $reader, ImagePhotographEvidence $evidence): int
    {
        try {
            $image = Image::query()->useWritePdo()->with(['product', 'imageAsset'])->findOrFail($this->argument('image'));
            $id = $this->option('media-id') ?: $image->shopify_id;
            if (! preg_match('~^gid://shopify/MediaImage/\d+$~', (string) $id) || ! $image->product?->shopify_id) {
                throw new RuntimeException('Supply a MediaImage id and an image with a current Shopify product.');
            }
            $node = collect($reader->media($image->product->shopify_id, 'VerifyOneShopifyPhotograph'))->first(fn (array $node): bool => ($node['id'] ?? null) === $id);
            if (! $node || ! ShopifyProductMediaReader::ready($node)) {
                throw new RuntimeException('The requested MediaImage is not attached and READY.');
            }
            $url = $node['originalSource']['url'] ?? $node['image']['url'] ?? '';
            $probe = $evidence->probe((string) $url);
            $expected = (string) $image->imageAsset?->sha256;
            $match = $expected !== '' && hash_equals($expected, $probe['sha256']);
            $this->line(json_encode(['cms_image_id' => $image->id, 'media_id' => $id, 'source' => isset($node['originalSource']['url']) ? 'original' : 'image', 'expected_sha256' => $expected, 'matches_backup' => $match] + $probe, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $this->info('Read-only verification. No CMS rows or Shopify media were modified.');

            return $match ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
