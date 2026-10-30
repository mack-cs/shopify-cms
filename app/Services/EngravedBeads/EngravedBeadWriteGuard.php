<?php

namespace App\Services\EngravedBeads;

final class EngravedBeadWriteGuard
{
    public function check(): void
    {
        if (! config('engraved_beads.enabled', true) || ! config('engraved_beads.inventory_writes_enabled', false)
            || ! config('engraved_beads.store_contract_verified', false)) {
            throw new \RuntimeException('Engraved inventory writes are blocked until flags and store contract verification are explicitly approved.');
        }
        if (version_compare((string) config('services.shopify.api_version', '2026-01'), '2026-04', '<')) {
            throw new \RuntimeException('Engraved operations require a verified Shopify API version supporting idempotency and quantity CAS (2026-04 or later).');
        }
    }
}
