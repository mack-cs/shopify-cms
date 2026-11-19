<?php

namespace App\Services;

use App\Models\Image;

/**
 * Classifies failed alt-text rows against current Shopify media.
 * It never publishes, repairs, or writes image rows.
 */
final class AltTextReconciliationReport
{
    public function __construct(private readonly ShopifyMediaImageResolver $media) {}

    /**
     * @return array<int, array<string, string>>
     */
    public function rows(string $status = Image::ALT_FAILED): array
    {
        return Image::query()
            ->with(['product.variants', 'imageAsset'])
            ->where('alt_text_status', $status)

            ->orderBy('id')
            ->get()
            ->map(fn (Image $image): array => $this->classify($image))
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    public function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not write the alt text reconciliation report.');
        }

        fputcsv($handle, [
            'cms_image_id',
            'product',
            'existing_shopify_id',
            'proposed_media_image_id',
            'evidence',
            'alt_text_approval_status',
            'recommended_action',
            'classification',
        ]);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['cms_image_id'],
                $row['product'],
                $row['existing_shopify_id'],
                $row['proposed_media_image_id'],
                $row['evidence'],
                $row['alt_text_approval_status'],
                $row['recommended_action'],
                $row['classification'],
            ]);
        }
        fclose($handle);
    }

    /**
     * @return array<string, string>
     */
    private function classify(Image $image): array
    {
        $decision = trim((string) $image->alt_text) === '' ? ['classification' => 'invalid_alt_text', 'proposed_media_id' => null, 'evidence' => 'The saved description is blank. Review it before publishing.'] : $this->media->diagnose($image->fresh(['product', 'imageAsset']) ?? $image);
        $classification = (string) $decision['classification'];

        return [
            'cms_image_id' => (string) $image->id,
            'product' => trim((string) ($image->product?->handle ?: $image->product?->title ?: $image->product_id)),
            'existing_shopify_id' => trim((string) $image->shopify_id),
            'proposed_media_image_id' => trim((string) ($decision['proposed_media_id'] ?? '')),
            'evidence' => (string) $decision['evidence'],
            'alt_text_approval_status' => trim((string) $image->alt_text_status),
            'recommended_action' => $this->action($classification),
            'classification' => $classification,
        ];
    }

    private function action(string $classification): string
    {
        return match ($classification) {
            'verified_current_media' => 'Review photograph provenance, current approval, and pending operations individually. This report does not authorize retries or uploads.',
            'recoverable_historical' => 'Review the verified candidate and approval individually. This report does not authorize retries, adoption, or uploads.',
            'invalid_alt_text' => 'Review the blank description. No publish or retry is authorized.',
            'api_error' => 'Shopify could not be read. Identity is unknown; do not treat this as missing or retry publishing.',
            'processing' => 'Wait for Shopify processing and run this read-only report again.',
            'unresolved_provenance' => 'Review photograph evidence. A historical URL alone cannot authorize publishing.',
            'ambiguous' => 'Review. More than one Shopify file matches. Do not publish or upload.',
            'replaced_or_invalid' => 'Review. The stored mapping belongs to a replaced photograph. Do not publish alt text onto the old Shopify file.',
            default => 'Review. No current Shopify file matches this photograph. Do not invent a media id.',
        };
    }
}
