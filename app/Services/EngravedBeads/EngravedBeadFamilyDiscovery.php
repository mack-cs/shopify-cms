<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Models\Product;
use App\Models\ShopifyInventorySnapshot;
use App\Models\Variant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Catalog-only discovery: no network calls, activation, or inventory mutations. */
final class EngravedBeadFamilyDiscovery
{
    public function tags(?string $tags): array
    {
        // Keep exact spelling and duplicates: normalized/deduplicated tokens hide conflicts.
        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', $tags ?? '') ?: [])));
    }

    public function inspect(): array
    {
        $skuKey = fn ($sku) => mb_strtoupper(preg_replace('/\s+/u', '', trim((string) $sku)));
        $skuCounts = Variant::query()->active()->pluck('sku')->map($skuKey)->countBy();
        $groups = [];
        foreach (Product::query()->with('variants')->orderBy('id')->cursor() as $product) {
            $tags = $this->tags($product->tags);
            $familyTags = array_values(array_filter($tags, fn ($t) => str_ends_with($t, '-bead-family')));
            foreach (array_unique($familyTags) as $tag) {
                $groups[$tag] ??= ['family_tag' => $tag, 'members' => [], 'errors' => []];
                $errors = [];
                if (count($familyTags) !== 1 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*-bead-family$/D', $tag)) {
                    $errors[] = "Product {$product->shopify_id}: exactly one valid family tag is required.";
                }
                $quantities = array_values(array_filter($tags, fn ($t) => preg_match('/^\d+-engraved-beads$/D', $t)));
                $beads = count($quantities) === 1 ? (EngravedBeadConfiguration::QUANTITY_TAGS[$quantities[0]] ?? null) : null;
                if ($beads === null) {
                    $errors[] = "Product {$product->shopify_id}: exactly one valid quantity tag is required.";
                }
                $variant = $product->variants->count() === 1 ? $product->variants->first() : null;
                if ($variant === null) {
                    $errors[] = "Product {$product->shopify_id}: exactly one variant is required.";
                }
                foreach (['Product' => $product->shopify_id, 'ProductVariant' => $variant?->shopify_id, 'InventoryItem' => $variant?->shopify_inventory_item_id] as $type => $id) {
                    if (! preg_match('~^gid://shopify/'.$type.'/[0-9]+$~D', (string) $id)) {
                        $errors[] = "Product {$product->shopify_id}: missing or invalid {$type} identity.";
                    }
                }
                if (trim((string) $variant?->sku) === '') {
                    $errors[] = "Product {$product->shopify_id}: missing SKU.";
                }
                if (($skuCounts[$skuKey($variant?->sku)] ?? 0) > 1) {
                    $errors[] = "Product {$product->shopify_id}: SKU is not unique across the synchronized catalog.";
                }
                if ($beads === 1 && $variant?->inventory_tracked !== true) {
                    $errors[] = "Product {$product->shopify_id}: the 1-bead source must be tracked.";
                } elseif ($beads !== null && $beads > 1 && $variant?->inventory_tracked !== false) {
                    $approved = EngravedBeadFamilyMember::query()->where('shopify_variant_id', $variant?->shopify_id)->first();
                    if (! ($approved?->automation_disabled && $variant?->inventory_tracked === true)) {
                        $errors[] = "Product {$product->shopify_id}: multi-bead member must be untracked unless disabled by automation.";
                    }
                }
                if ($beads !== null && $beads > 1 && $variant?->current_on_hand_quantity !== 0) {
                    $errors[] = "Product {$product->shopify_id}: verify zero independent on-hand member inventory before cutover.";
                }
                $groups[$tag]['members'][] = [
                    'shopify_product_id' => $product->shopify_id,
                    'shopify_variant_id' => $variant?->shopify_id,
                    'shopify_inventory_item_id' => $variant?->shopify_inventory_item_id,
                    'sku' => trim((string) $variant?->sku), 'required_beads' => $beads,
                    'last_verified_tracked' => $variant?->inventory_tracked,
                    'last_verified_sellable' => $variant?->shopify_available_for_sale,
                ];
                array_push($groups[$tag]['errors'], ...$errors);
            }
        }
        foreach ($groups as &$group) {
            usort($group['members'], fn ($a, $b) => [$a['required_beads'], $a['shopify_product_id']] <=> [$b['required_beads'], $b['shopify_product_id']]);
            if (array_column($group['members'], 'required_beads') !== [1, 2, 3, 4]) {
                $group['errors'][] = 'Exactly one member for each quantity 1, 2, 3 and 4 is required.';
            }
            foreach (['shopify_product_id', 'shopify_variant_id', 'shopify_inventory_item_id', 'sku'] as $key) {
                $values = array_column($group['members'], $key);
                if (count(array_unique($values)) !== count($values)) {
                    $group['errors'][] = "Duplicate {$key} in family.";
                }
            }
            $source = collect($group['members'])->firstWhere('required_beads', 1);
            $locations = $source ? ShopifyInventorySnapshot::query()
                ->where('shopify_inventory_item_id', $source['shopify_inventory_item_id'])
                ->where('location_active', true)->distinct()->pluck('shopify_location_id')->filter()->values()->all() : [];
            $configured = trim((string) config('services.shopify.inventory_location_id'));
            // Never choose a location by order or by the first remote location.
            $group['source_location_id'] = count($locations) === 1 && ($configured === '' || $configured === $locations[0]) ? $locations[0] : null;
            if ($group['source_location_id'] === null) {
                $group['errors'][] = 'Exactly one verified source location is required; refresh inventory snapshots.';
            }
            $group['mapping_hash'] = hash('sha256', json_encode([
                $group['family_tag'], $group['source_location_id'],
                array_map(fn ($m) => array_intersect_key($m, array_flip(['shopify_product_id', 'shopify_variant_id', 'shopify_inventory_item_id', 'sku', 'required_beads'])), $group['members']),
            ], JSON_THROW_ON_ERROR));
        }
        unset($group);

        return $groups;
    }

    public function synchronize(): array
    {
        // Global discovery serialization also protects cross-family unique identities.
        return Cache::lock('engraved-bead-discovery', 120)->block(5, function (): array {
            $groups = $this->inspect();
            DB::transaction(function () use ($groups): void {
                foreach ($groups as $tag => $group) {
                    $family = EngravedBeadFamily::query()->firstOrCreate(['family_tag' => $tag], [
                        'display_name' => Str::headline(Str::beforeLast($tag, '-bead-family')),
                    ]);
                    $this->persist($family, $group);
                }
                foreach (EngravedBeadFamily::query()->whereNotIn('family_tag', array_keys($groups))->get() as $family) {
                    $this->persist($family, ['mapping_hash' => null, 'members' => [], 'errors' => ['Family tag removed from synchronized catalog.']]);
                }
            });

            return $groups;
        });
    }

    private function persist(EngravedBeadFamily $family, array $group): void
    {
        $family = EngravedBeadFamily::query()->lockForUpdate()->findOrFail($family->id);
        $protected = $family->activated_at !== null;
        $conflict = $protected && ($family->mapping_hash !== $group['mapping_hash'] || $group['errors'] !== []);
        $errors = $group['errors'];
        foreach ($group['members'] as $member) {
            if (EngravedBeadFamilyMember::query()->where('family_id', '!=', $family->id)
                ->where(fn ($q) => $q->where('shopify_product_id', $member['shopify_product_id'])
                    ->orWhere('shopify_variant_id', $member['shopify_variant_id'])
                    ->orWhere('shopify_inventory_item_id', $member['shopify_inventory_item_id']))->exists()) {
                $errors[] = 'Member identity already belongs to another persisted family; explicit reconciliation required.';
            }
        }
        $status = $protected ? (($conflict || $errors !== []) ? 'mapping_conflict'
            : ($family->status !== 'active' ? $family->status : 'active')) : 'pending_validation';
        if ($family->observed_mapping !== $group || $family->status !== $status) {
            $family->audits()->create(['action' => 'discovery', 'details' => ['errors' => $errors, 'observed' => $group, 'status' => $status]]);
        }
        $family->fill(['status' => $status, 'validation_errors' => $errors, 'observed_mapping' => $group,
            'validated_at' => $errors === [] ? now() : null]);
        if (! $protected && $errors === []) {
            if ($family->mapping_hash !== $group['mapping_hash']) {
                $family->members()->delete();
            }
            foreach ($group['members'] as $member) {
                $existing = $family->members()->where('shopify_variant_id', $member['shopify_variant_id'])->first();
                if ($existing) {
                    $existing->update($member);
                } else {
                    $family->members()->create($member + ['disable_threshold' => $member['required_beads']]);
                }
            }
            $source = collect($group['members'])->firstWhere('required_beads', 1);
            $family->fill(['mapping_hash' => $group['mapping_hash'], 'source_product_id' => $source['shopify_product_id'],
                'source_variant_id' => $source['shopify_variant_id'], 'source_inventory_item_id' => $source['shopify_inventory_item_id'],
                'source_location_id' => $group['source_location_id']]);
        }
        if ($protected && ! $conflict && $errors === []) {
            foreach ($group['members'] as $member) {
                $family->members()->where('shopify_variant_id', $member['shopify_variant_id'])->update([
                    'last_verified_tracked' => $member['last_verified_tracked'], 'last_verified_sellable' => $member['last_verified_sellable'],
                ]);
            }
        }
        $family->save();
    }
}
