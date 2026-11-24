<?php

namespace App\Services;

use App\Models\Image;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class ImagePhotographEvidence
{
    public function archiveSource(object $source, string $action): void
    {
        DB::table('inherited_image_source_events')->insert([
            'image_id' => $source->image_id, 'source_link_id' => $source->id,
            'action' => $action, 'snapshot' => json_encode($source, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function archive(Image $image, string $reason): void
    {
        if ($image->exists) {
            DB::table('image_revisions')->insert([
                'image_id' => $image->id,
                'snapshot' => json_encode($image->getRawOriginal(), JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }
    }

    public function matchesMedia(Image $image, array $node): bool
    {
        // Prefer the freshly signed original, not a recompressed preview.
        $url = $node['originalSource']['url'] ?? $node['image']['url'] ?? '';

        return $this->matches($image, (string) $url);
    }

    /** Historical URLs locate candidates; current original bytes establish identity. */
    public function matches(Image $image, string $url): bool
    {
        $image->loadMissing('imageAsset');
        $hash = (string) $image->imageAsset?->sha256;
        if ($hash === '') {
            return false;
        }
        try {
            $probe = $this->probe($url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return hash_equals($hash, $probe['sha256']);
    }

    /** Exact hosts only: no arbitrary buckets, storefronts, IPs, or suffix matching. */
    public const TRUSTED_HOSTS = ['cdn.shopify.com', 'shopify-shop-assets.storage.googleapis.com'];

    public function trustedHost(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($host, self::TRUSTED_HOSTS, true)) {
            throw new \InvalidArgumentException('Photograph URL violates the trusted HTTPS host policy.');
        }

        return $host;
    }

    /** Read-only; signed URL query parameters are never included in diagnostics. */
    public function probe(string $url): array
    {
        $fetched = $this->fetch($url);

        return [
            'sha256' => hash('sha256', $fetched['body']),
            'bytes' => strlen($fetched['body']),
            'trace' => $fetched['trace'],
        ];
    }

    /** The original bytes from a trusted Shopify host. Callers decide whether those bytes are the current photograph. */
    public function download(string $url): string
    {
        return $this->fetch($url)['body'];
    }

    /**
     * @return array{body: string, trace: array<int, array{host: string, status: int}>}
     */
    private function fetch(string $url): array
    {
        $seen = [];
        $trace = [];
        for ($hop = 0; $hop <= 3; $hop++) {
            $host = $this->trustedHost($url);
            if (isset($seen[$url])) {
                throw new \RuntimeException('Photograph redirect loop.');
            }
            $seen[$url] = true;
            try {
                $response = Http::connectTimeout(10)->timeout(30)
                    ->withHeaders(['Accept-Encoding' => 'identity'])
                    ->withOptions(['allow_redirects' => false, 'verify' => true,
                        'progress' => function ($total, $downloaded): void {
                            if ($total > 33554432 || $downloaded > 33554432) {
                                throw new \RuntimeException('Photograph exceeds the 32 MiB evidence limit.');
                            }
                        },
                    ])->get($url);
            } catch (\Throwable $exception) {
                throw new \RuntimeException('Photograph download failed on trusted host '.$host.'.', previous: $exception);
            }
            $trace[] = ['host' => $host, 'status' => $response->status()];
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! is_string($location) || $location === '' || $hop === 3) {
                    throw new \RuntimeException('Photograph redirect limit or missing destination.');
                }
                // Resolve relative URLs, then validate before sending the next request.
                try {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                } catch (\Throwable) {
                    throw new \InvalidArgumentException('Malformed photograph redirect destination.');
                }
                $this->trustedHost($url);

                continue;
            }
            if ($response->status() !== 200) {
                throw new \RuntimeException('Photograph download returned HTTP '.$response->status().' on '.$host.'.');
            }
            $body = $response->body();
            if ($body === '' || strlen($body) > 33554432) {
                throw new \RuntimeException('Photograph download is empty or exceeds the evidence limit.');
            }

            return ['body' => $body, 'trace' => $trace];
        }
        throw new \RuntimeException('Photograph redirect limit exceeded.');
    }
}
