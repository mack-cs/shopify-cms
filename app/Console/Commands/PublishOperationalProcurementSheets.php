<?php

namespace App\Console\Commands;

use App\Models\NewProductDraft;
use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;

class PublishOperationalProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-operational
        {--variant=* : Optional local variant IDs, or SKUs for local testing}
        {--sku=* : Optional SKUs to publish}
        {--append-missing : Append missing targeted rows instead of updating existing rows only}';
    protected $description = 'Publish current inventory and CMS supplier-order fields without rerunning ML';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $resolution = $this->variantIds();
        $ids = $resolution['ids'];
        $draftIds = $resolution['draft_ids'];
        foreach ($resolution['warnings'] as $warning) {
            $this->warn($warning);
        }

        $appendMissing = (bool) $this->option('append-missing');
        if ($appendMissing && $ids === [] && $draftIds === []) {
            $this->error('Use --append-missing with at least one --variant or --sku value.');

            return self::FAILURE;
        }

        $result = $sheets->publishOperational($ids, appendMissing: $appendMissing, draftIds: $draftIds);
        $this->info("Published {$result['rows']} operational row update(s) across {$result['tabs']} tab(s).");
        return self::SUCCESS;
    }

    /** @return array{ids:array<int,int>,draft_ids:array<int,int>,warnings:array<int,string>} */
    private function variantIds(): array
    {
        $ids = collect($this->option('variant'))
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->filter(fn (mixed $value): bool => ctype_digit(trim((string) $value)))
            ->map(fn (mixed $id): int => (int) $id);

        $skus = collect([...$this->option('sku'), ...$this->option('variant')])
            ->map(fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '' && ! ctype_digit($value))
            ->unique()
            ->values();

        $warnings = [];
        $draftIds = collect();
        if ($skus->isNotEmpty()) {
            $variants = Variant::query()
                ->with('product:id,title,handle,status,is_bundle')
                ->whereIn(\DB::raw('UPPER(TRIM(sku))'), $skus->all())
                ->get(['id', 'product_id', 'sku', 'sync_state']);
            $eligibleVariants = $variants->filter(fn (Variant $variant): bool => $this->variantIsProcurementEligible($variant));
            $matched = $eligibleVariants->pluck('id');

            $ids = $ids->merge($matched);
            $matchedSkus = $variants
                ->pluck('sku')
                ->map(fn (mixed $sku): string => strtoupper(trim((string) $sku)))
                ->all();
            $eligibleVariantSkus = $eligibleVariants
                ->pluck('sku')
                ->map(fn (mixed $sku): string => strtoupper(trim((string) $sku)))
                ->all();
            $draftCandidateSkus = $skus->diff($eligibleVariantSkus)->values();

            if ($draftCandidateSkus->isNotEmpty()) {
                $drafts = NewProductDraft::query()
                    ->whereIn(\DB::raw('UPPER(TRIM(sku))'), $draftCandidateSkus->all())
                    ->get(['id', 'sku', 'title', 'handle', 'status']);
                $eligibleDrafts = $drafts->filter(fn (NewProductDraft $draft): bool => $this->draftIsProcurementEligible($draft));
                $draftIds = $draftIds->merge($eligibleDrafts->pluck('id'));
                $draftSkus = $drafts
                    ->pluck('sku')
                    ->map(fn (mixed $sku): string => strtoupper(trim((string) $sku)))
                    ->all();

                foreach ($draftCandidateSkus->diff($matchedSkus)->diff($draftSkus)->values() as $sku) {
                    $warnings[] = "SKU [{$sku}] did not match a local Variant or New Product Draft.";
                }

                foreach ($drafts as $draft) {
                    $reasons = $this->draftExclusionReasons($draft);
                    if ($reasons !== []) {
                        $warnings[] = 'SKU ['.strtoupper(trim((string) $draft->sku)).'] matched draft #'.$draft->id
                            .' but is excluded from procurement: '.implode('; ', $reasons).'.';
                    }
                }
            }

            foreach ($variants as $variant) {
                $reasons = $this->variantExclusionReasons($variant);
                if ($reasons !== []) {
                    $warnings[] = 'SKU ['.strtoupper(trim((string) $variant->sku)).'] matched variant #'.$variant->id
                        .' but is excluded from procurement: '.implode('; ', $reasons).'.';
                }
            }
        }

        return [
            'ids' => $ids->unique()->values()->all(),
            'draft_ids' => $draftIds->unique()->values()->all(),
            'warnings' => $warnings,
        ];
    }

    private function variantIsProcurementEligible(Variant $variant): bool
    {
        return $this->variantExclusionReasons($variant) === [];
    }

    /** @return array<int,string> */
    private function variantExclusionReasons(Variant $variant): array
    {
        $product = $variant->product;
        $status = strtolower(trim((string) ($product?->status ?? '')));
        $title = strtolower((string) ($product?->title ?? ''));
        $handle = strtolower((string) ($product?->handle ?? ''));
        $isTest = str_contains($title, 'test') || str_contains($handle, 'test');
        $reasons = [];
        if (in_array($variant->sync_state, [Variant::SYNC_STATE_LOCAL_DELETED, Variant::SYNC_STATE_REMOTE_DELETED], true)) {
            $reasons[] = "variant sync_state is {$variant->sync_state}";
        }
        if ($status !== 'active') {
            $reasons[] = "product status is {$status}";
        }
        if ((bool) ($product?->is_bundle ?? false)) {
            $reasons[] = 'product is a stack/bundle';
        }
        if ($isTest && ! (bool) config('procurement.include_test_products', false)) {
            $reasons[] = 'PROCUREMENT_INCLUDE_TEST_PRODUCTS is false';
        }

        return $reasons;
    }

    private function draftIsProcurementEligible(NewProductDraft $draft): bool
    {
        return $this->draftExclusionReasons($draft) === [];
    }

    /** @return array<int,string> */
    private function draftExclusionReasons(NewProductDraft $draft): array
    {
        $status = strtolower(trim((string) ($draft->status ?? '')));
        $haystack = strtolower(implode(' ', [
            (string) $draft->sku,
            (string) $draft->title,
            (string) $draft->handle,
        ]));
        $reasons = [];
        if (! in_array($status, ['active', 'draft'], true)) {
            $reasons[] = "draft status is {$status}";
        }
        if (str_contains($haystack, 'test') && ! (bool) config('procurement.include_test_products', false)) {
            $reasons[] = 'PROCUREMENT_INCLUDE_TEST_PRODUCTS is false';
        }

        return $reasons;
    }
}
