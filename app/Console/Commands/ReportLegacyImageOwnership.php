<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Product;
use App\Services\ImageOwnership;
use App\Services\ProductPartialApprovalService;
use App\Services\StackComponentCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Lists unclassified stack images. It does not assign an ownership type. */
final class ReportLegacyImageOwnership extends Command
{
    protected $signature = 'images:legacy-image-ownership-report {--output=}';

    protected $description = 'Read-only report of stack images with no explicit ownership. Nothing is classified or changed.';

    public function handle(ProductPartialApprovalService $approvals, StackComponentCatalog $catalog): int
    {
        $rows = Image::query()
            ->with(['product', 'imageAsset'])
            ->where(function ($query): void {
                $query->whereNull('ownership')
                    ->orWhereNotIn('ownership', [ImageOwnership::STACK_OWNED, ImageOwnership::COMPONENT_INHERITED]);
            })
            ->orderBy('id')
            ->get()
            ->filter(function (Image $image): bool {
                $product = $image->product;

                return $product instanceof Product && $product->isStackProduct()
                    && ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED;
            });
        $path = trim((string) $this->option('output'));
        $handle = $path !== '' ? fopen($path, 'wb') : null;
        if ($path !== '' && $handle === false) {
            $this->error('Could not write the report.');

            return self::FAILURE;
        }
        $header = [
            'cms_image_id', 'product_id', 'handle', 'position', 'ownership_column', 'classification',
            'asset_id', 'asset_sha256', 'shopify_media_id', 'alt_text_status', 'approved_alt_present',
            'revision_count', 'approval_version', 'current_approval_count',
            'source_image_id', 'source_product_id', 'provenance_component_ids', 'bundle_component_ids',
        ];
        if ($handle) {
            fputcsv($handle, $header);
        } else {
            $this->line(implode(',', $header));
        }
        foreach ($rows as $image) {
            $product = $image->product;
            $provenance = DB::table('inherited_image_sources')->where('image_id', $image->id)->pluck('component_product_id')->implode('|');
            $source = $image->source_image_id ? Image::query()->find($image->source_image_id) : null;
            $line = [
                (string) $image->id,
                (string) $image->product_id,
                (string) ($product?->handle ?? ''),
                (string) $image->position,
                (string) ($image->ownership ?? ''),
                $image->ownership === null || trim((string) $image->ownership) === ''
                    ? 'null'
                    : ($image->ownership === ImageOwnership::UNRESOLVED ? 'explicit_unresolved' : 'invalid'),
                (string) ($image->image_asset_id ?? ''),
                (string) ($image->imageAsset?->sha256 ?? ''),
                (string) ($image->shopify_id ?? ''),
                (string) ($image->alt_text_status ?? ''),
                $approvals->approvedAlt($image) !== null ? 'yes' : 'no',
                (string) DB::table('image_revisions')->where('image_id', $image->id)->count(),
                (string) ($product?->approval_version ?? ''),
                (string) ($product instanceof Product ? $product->approvalsForCurrentVersionCount() : 0),
                (string) ($image->source_image_id ?? ''),
                (string) ($source?->product_id ?? ''),
                $provenance,
                implode('|', $product instanceof Product ? $catalog->componentIds($product) : []),
            ];
            if ($handle) {
                fputcsv($handle, $line);
            } else {
                $this->line(implode(',', $line));
            }
        }
        if ($handle) {
            fclose($handle);
        }
        $this->info($rows->count().' unclassified stack image(s). No ownership was assigned.');

        return self::SUCCESS;
    }
}
