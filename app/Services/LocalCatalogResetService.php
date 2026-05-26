<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalCatalogResetService
{
    /**
     * @return array{products:int,drafts:int}
     */
    public function reset(): array
    {
        if (!app()->isLocal()) {
            throw new RuntimeException('Local catalog reset is only available in the local environment.');
        }

        $summary = [
            'products' => (int) DB::table('products')->count(),
            'drafts' => (int) DB::table('new_product_drafts')->count(),
        ];

        $tables = [
            'approvals',
            'change_logs',
            'deletion_requests',
            'images',
            'new_product_draft_approvals',
            'new_product_draft_assignment_items',
            'new_product_draft_assignment_logs',
            'new_product_draft_assignments',
            'new_product_drafts',
            'product_partial_approval_requests',
            'product_url_redirects',
            'products',
            'shopify_audits',
            'shopify_metafields',
            'shopify_missing_products',
            'shopify_rows',
            'style_profiles',
            'variants',
        ];

        $this->disableForeignKeyChecks();

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            $this->enableForeignKeyChecks();
        }

        return $summary;
    }

    private function disableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            default => null,
        };
    }

    private function enableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            default => null,
        };
    }
}
