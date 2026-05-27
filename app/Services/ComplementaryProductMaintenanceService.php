<?php

namespace App\Services;

use App\Mail\ComplementaryProductMaintenanceAlertMail;
use App\Models\Product;
use App\Models\ShopifyAudit;
use Illuminate\Support\Facades\Mail;

class ComplementaryProductMaintenanceService
{
    public function __construct(
        private readonly ComplementaryProductAuditService $auditService,
    ) {
    }

    /**
     * @return array{
     *   checked:int,
     *   recorded:int,
     *   healthy:int,
     *   flagged:int,
     *   notified:int
     * }
     */
    public function runDailyCheck(): array
    {
        $checked = 0;
        $recorded = 0;
        $healthy = 0;
        $flagged = 0;
        $notified = 0;
        $alerts = [];

        Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'title', 'status', 'last_synced_at', 'updated_at'])
            ->whereIn(\DB::raw('LOWER(COALESCE(status, ""))'), ['active', 'draft'])
            ->chunkById(200, function ($products) use (&$checked, &$recorded, &$healthy, &$flagged, &$alerts): void {
                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $checked++;
                    $audit = $this->recordAuditForProduct($product);
                    $analysis = $audit->details ?? [];
                    $needsAttention = (bool) $audit->needs_attention;

                    $recorded++;

                    if ($needsAttention) {
                        $flagged++;
                        $alerts[] = [
                            'product' => $product,
                            'local_total' => (int) ($audit->local_saved_count ?? 0),
                            'local_eligible' => (int) ($audit->local_valid_count ?? 0),
                            'shopify_current' => (int) ($audit->shopify_current_count ?? 0),
                            'shopify_eligible' => (int) ($audit->shopify_valid_count ?? 0),
                            'local_ineligible' => $analysis['local_ineligible'] ?? [],
                            'shopify_ineligible' => $analysis['shopify_ineligible'] ?? [],
                        ];
                    } else {
                        $healthy++;
                    }
                }
            });

        if ($alerts !== []) {
            $recipientEmails = $this->recipientEmails();
            if ($recipientEmails !== []) {
                Mail::to($recipientEmails)->send(new ComplementaryProductMaintenanceAlertMail($alerts));
                $notified = count($alerts);

                ShopifyAudit::query()
                    ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                    ->where('needs_attention', true)
                    ->update([
                        'last_notified_at' => now(),
                    ]);
            }
        }

        return [
            'checked' => $checked,
            'recorded' => $recorded,
            'healthy' => $healthy,
            'flagged' => $flagged,
            'notified' => $notified,
        ];
    }

    public function recordAuditForProduct(Product $product): ShopifyAudit
    {
        $analysis = $this->auditService->analyzeProduct($product);
        $needsAttention = !($analysis['shopify_good'] ?? false);

        return ShopifyAudit::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'audit_type' => ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS,
            ],
            [
                'status' => $needsAttention ? ShopifyAudit::STATUS_FLAGGED : ShopifyAudit::STATUS_HEALTHY,
                'needs_attention' => $needsAttention,
                'local_saved_count' => (int) ($analysis['local_total'] ?? 0),
                'local_valid_count' => count($analysis['local_eligible_ids'] ?? []),
                'shopify_current_count' => (int) ($analysis['shopify_total'] ?? 0),
                'shopify_valid_count' => (int) ($analysis['shopify_eligible'] ?? 0),
                'details' => [
                    'local_total' => (int) ($analysis['local_total'] ?? 0),
                    'shopify_total' => (int) ($analysis['shopify_total'] ?? 0),
                    'shopify_eligible' => (int) ($analysis['shopify_eligible'] ?? 0),
                    'local_ids' => $analysis['local_ids'] ?? [],
                    'local_primary_ids' => $analysis['local_primary_ids'] ?? [],
                    'local_eligible_ids' => $analysis['local_eligible_ids'] ?? [],
                    'local_ineligible' => $analysis['local_ineligible'] ?? [],
                    'shopify_ids' => $analysis['shopify_ids'] ?? [],
                    'shopify_eligible_ids' => $analysis['shopify_eligible_ids'] ?? [],
                    'shopify_missing_local_ids' => $analysis['shopify_missing_local_ids'] ?? [],
                    'shopify_missing_local' => $analysis['shopify_missing_local'] ?? [],
                    'shopify_ineligible' => $analysis['shopify_ineligible'] ?? [],
                    'desired_shopify_gids' => $analysis['desired_shopify_gids'] ?? [],
                    'audit_source' => 'live_shopify_admin_graphql',
                ],
                'last_checked_at' => now(),
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    private function recipientEmails(): array
    {
        return ['shonaymack@mackscs.com'];
    }
}
