# Stack gallery rebuild — manual implementation

The corrected scope below is implemented in the working tree. Cursor can use this file as a walkthrough and compare it with `ComponentPhotographSelector`, `ShopifyImageImportService`, `ComponentStackPropagation`, and the focused tests in `StackImageRebuildSourceTest.php`.

> **Superseded. Do not apply the replacement code below as written.** It would remove the Shopify source you rely on and its new-row code would still leave `src` empty. The investigation below records the corrected scope. The remaining sections are retained for reference only.

## Follow-up investigation — component URLs and duplicate rows

- The manual picker uses the associated component row's `src`. Selecting a picture copies that URL into the image form. The URL can point to Shopify or a CMS backup route. The screenshot illustrates the intended distinction: position 1 is the product photograph, and position 2 is a component lifestyle photograph. See `ImagesRelationManager.php` around lines 59 and 587.
- The older rebuild at `41c345f` picked one candidate per component through `firstComponentShopifyImage()`, deduplicated by asset ID, and explicitly set each new row's `src` to the managed backup route. The same older code required a Shopify ID and could recreate rows, so reverting the whole file would also restore those limitations. By `ee3283d`, the rebuild was delegated to `stageStack()`, which walks all active component images.
- The current inherited-row creator sets `image_asset_id` but leaves `src` unset. The All Images thumbnail reads `src` directly. A valid asset can therefore show up as a blank thumbnail. The current reuse query matches inherited ownership plus asset ID, so a legacy or manually reused row with missing ownership can be missed even when it contains the same photo. The new row then sits beside the existing row.

An isolated rebuild reproduced all three symptoms in five assertions. It started with one stack-owned row and one legacy row already showing the component primary. It finished with four active rows: the two original rows, a new inherited duplicate of the primary with `src = NULL`, and a new inherited copy of the component's position-2 lifestyle image with `src = NULL`. No HTTP requests were sent.

### Corrected implementation scope

1. Select **exactly position 1** and its alt text for each component. Both source preparation and staging must use that same selection. Keep the existing Shopify original fallback for that selected photo and add safe handling of an existing component Shopify CDN URL where appropriate. A readable backup, local file, or trusted Shopify source is usable without waiting for a new Shopify publication. Prefer a verified managed asset where available. Use URL evidence for local display and keep Shopify publication authorization separate. Never interpret a component URL as the stack's own Shopify media ID.
2. Make the rebuilt row render. Set a usable CMS backup URL after saving a managed row, as the older code did, or use the backup URL as the thumbnail fallback. For a URL-backed row, use the validated source URL. Do not create a placeholder row without a usable source.
3. Reuse a matching inherited row first. Check legacy/manual rows for an exact, unambiguous component photo match by asset or canonical source URL before creating another. Preserve stack-owned photos, and leave ambiguous ownership for review instead of silently reclassifying a row. Clean up proven extra inherited rows locally while keeping Shopify IDs and avoiding remote deletion.
4. Test two stack-owned lifestyle photos, one backup-backed primary, one Shopify-backed primary, a component position-2 lifestyle photo, and a legacy copy of one of the primaries. Rebuild twice. Expect the same ordered row IDs, visible thumbnails, matching alt text, no secondary photo, and no extra copy of a known primary.

For production product `519`, the screenshot does not identify which visible rows are legacy, hidden, or newly inherited. Inspect the rows read-only before cleanup:

```sql
SELECT i.id, i.position, i.ownership, i.source_image_id, i.image_asset_id,
       a.sha256, i.src, i.shopify_id, i.sync_state, i.is_duplicate_hidden
FROM images i
LEFT JOIN image_assets a ON a.id = i.image_asset_id
WHERE i.product_id = 519
ORDER BY i.position, i.id;
```

## Decision

Keep the current S3 filename parser, stack-owned import, queue entry points, batch status, gallery mutex, and Shopify publication safeguards. Replace the local rebuild's image-selection and fallback logic. A wholesale revert would also remove working stack-owned imports and the protections that keep Shopify identities attached to their rows.

The current code selects every active component image in both `ComponentStackPropagation::stageStackWhileLocked()` and `ShopifyImageImportService::prepareComponentSources()`. It then scans them again to assign positions. The Shopify fallback adds remote reads to a workflow that only needs files already in the CMS. The changes below give every local rebuild entry point the same rule.

**Rule:** Keep the stack-owned lifestyle photos in their existing positions. Start component photos after the last occupied stack-owned or unclassified position. In component order, read only the single active image explicitly at position 1 and its alt text. Missing, ambiguous, or unreadable position 1 produces an incomplete result. Position 2 is never a substitute. Store one inherited row per distinct photo asset, with provenance for each component that shares it. For shared bytes, the first eligible component in membership order supplies the local alt text. The existing Shopify conflict checks still govern publication.

## 1. `app/Services/ComponentPhotographSelector.php`

Replace `selected()` with these two methods. `positionOne()` is also usable before a managed backup exists. Existing publication callers keep the stricter `selected()` behavior.

```php
public function positionOne(Product $product): ?Image
{
    $primary = $product->allImages()->active()
        ->where('position', 1)
        ->with('imageAsset')
        ->limit(2)
        ->get();

    return $primary->count() === 1 ? $primary->first() : null;
}

public function selected(Product $product): ?Image
{
    $image = $this->positionOne($product);

    return $image instanceof Image && (int) $image->image_asset_id > 0 ? $image : null;
}
```

## 2. `app/Services/ShopifyImageImportService.php`

Remove the `$componentMediaReads` property and the entire methods `prepareComponentSources()`, `prepareInheritedSource()`, `verifiedShopifyPhotograph()`, `componentMedia()`, `attachVerifiedBackup()`, and `mergeUnavailableSources()`. Keep the S3 importer’s existing `managedAssetBytes()`, `localPathBytes()`, and `storeBytesAsAsset()`. Replace the removed source preparation with this CMS-only method. It accepts the already-selected image; it never selects or downloads another.

```php
public function prepareLocalStackSource(Image $image): ?string
{
    $stored = strtolower(trim((string) $image->imageAsset?->sha256));
    $managed = $this->managedAssetBytes($image);
    if (is_string($managed) && $stored !== '') {
        return null;
    }

    $local = $this->localPathBytes($image);
    if (! is_string($local)) {
        return 'the CMS photograph has no readable, verified backup or local file.';
    }

    if ((int) $image->image_asset_id > 0
        && ($stored === '' || ! hash_equals($stored, hash('sha256', $local)))) {
        return 'the local CMS photograph does not match the recorded SHA-256.';
    }

    $extension = $this->extensionFromName($image->image_path);
    $asset = $this->storeBytesAsAsset(
        $local,
        $extension,
        $this->mimeTypeFromExtension($extension),
        basename((string) $image->image_path),
        null,
    );

    if ((int) $image->image_asset_id > 0 && (int) $image->image_asset_id !== (int) $asset->id) {
        return 'the recovered CMS file does not match the recorded asset.';
    }

    // This attaches backup metadata for the same CMS file. It does not change its content version.
    DB::table('images')->where('id', $image->id)->update([
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'updated_at' => now(),
    ]);
    $image->refresh()->setRelation('imageAsset', $asset);

    return null;
}
```

Replace `rebuildStackImagesLocked()` with the following. Keep the outer `rebuildStackImages()` mutex, the claim check, and the existing batch/status code.

```php
private function rebuildStackImagesLocked(Product $stack, ShopifyImageImportBatch $batch, ?array $claim = null): array
{
    return DB::transaction(function () use ($stack, $batch, $claim): array {
        $locked = Product::query()->whereKey($stack->id)->lockForUpdate()->first();
        if (! $locked instanceof Product || ! $this->rebuildAttemptMayCommit((int) $locked->id, $batch, $claim, $locked)) {
            return $this->skippedRebuild();
        }

        $result = app(ComponentStackPropagation::class)->stageStack($locked);

        if (! $this->rebuildAttemptMayCommit((int) $locked->id, $batch, $claim, $locked)) {
            throw new \RuntimeException('The rebuild lost its claim before the gallery could be committed.');
        }

        return $result;
    });
}
```

## 3. `app/Services/ComponentStackPropagation.php`

Add the constant inside the class. Replace `stageStack()` and `stageStackWhileLocked()` with the code below. Remove `desiredInheritedPositions()`, `assignInheritedGalleryOrder()`, and `retireInheritedAbsentFromGallery()`; in the reviewed tree their callers are confined to the methods being replaced. Keep `unavailablePhotographReason()` so staging verifies the managed bytes independently.

The local rebuild reversibly hides obsolete inherited rows using the existing visibility fields and a dedicated reason. The row, Shopify media ID, and audit trail survive. An earlier primary that becomes position 1 again can reuse its original row. Stack-owned and unclassified rows are left alone.

```php
public const OUTSIDE_PRIMARY_GALLERY = 'Outside the component position-1 gallery';

public function stageStack(Product $stack): array
{
    $boundary = app(StackSynchronizationBoundary::class)->acquireForStacks([(int) $stack->id]);
    try {
        return DB::transaction(fn (): array => $this->stageStackWhileLocked($stack));
    } finally {
        $boundary->release();
    }
}

private function stageStackWhileLocked(Product $stack): array
{
    if ($this->catalog->membershipState($stack) === 'unknown') {
        return [
            'rebuilt' => false,
            'message' => "{$stack->handle}: component membership is unknown. Gallery unchanged.",
            'created' => [],
            'unavailable' => [],
        ];
    }

    $sourcesByAsset = [];
    $unavailable = [];
    foreach ($this->catalog->componentIds($stack) as $componentId) {
        $component = Product::query()->find($componentId);
        $source = $component instanceof Product ? $this->selector->positionOne($component) : null;
        $label = $component?->handle ?: 'Component #'.$componentId;
        if (! $source instanceof Image) {
            $unavailable[] = $label.': missing or ambiguous position-1 photograph.';

            continue;
        }

        $reason = app(ShopifyImageImportService::class)->prepareLocalStackSource($source)
            ?? $this->unavailablePhotographReason($source);
        if ($reason !== null) {
            $filename = $source->imageAsset?->original_filename ?: $source->preferredFilename();
            $unavailable[] = $label.' image '.$source->id.' ('.$filename.'): '.$reason;

            continue;
        }
        $sourcesByAsset[(int) $source->image_asset_id][] = $source;
    }

    $gallery = $stack->allImages()->lockForUpdate()->orderBy('id')->get();
    $activeIds = $stack->allImages()->active()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $position = 1;
    foreach ($gallery as $image) {
        if (in_array((int) $image->id, $activeIds, true) && ! ImageOwnership::isInherited($image)) {
            $position = max($position, (int) $image->position);
        }
    }

    $kept = [];
    foreach ($sourcesByAsset as $assetId => $sources) {
        $source = $sources[0];
        $candidates = $gallery->filter(fn (Image $image): bool =>
            ImageOwnership::isInherited($image)
            && (int) $image->image_asset_id === $assetId
            && (in_array((int) $image->id, $activeIds, true)
                || ($image->duplicate_hidden_reason === self::OUTSIDE_PRIMARY_GALLERY
                    && ! in_array($image->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true)))
        );
        $copy = $candidates->first(fn (Image $image): bool =>
            (int) $image->source_image_id === (int) $source->id
        ) ?? $candidates->first() ?? new Image;

        if (! $copy->exists) {
            $copy->forceFill([
                'product_id' => $stack->id,
                'ownership' => ImageOwnership::COMPONENT_INHERITED,
                'image_asset_id' => $assetId,
                'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                'backup_completed_at' => now(),
                'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
                'local_dirty' => true,
                'needs_shopify_image_sync' => true,
                'approved_filename' => $source->preferredFilename(),
                'filename_mode' => Image::FILENAME_MODE_MANUAL,
            ]);
        }

        $copy->forceFill(['source_image_id' => $source->id, 'position' => ++$position]);
        if ($copy->duplicate_hidden_reason === self::OUTSIDE_PRIMARY_GALLERY) {
            $copy->forceFill([
                'is_duplicate_hidden' => false,
                'duplicate_hidden_at' => null,
                'duplicate_hidden_reason' => null,
            ]);
        }
        if ((string) $copy->alt_text !== (string) $source->alt_text && $copy->alt_confirmation_media_id === null) {
            if ($copy->exists) {
                app(ImagePhotographEvidence::class)->archive($copy, 'Stack alt now follows the component primary');
            }
            $copy->forceFill([
                'alt_text' => $source->alt_text,
                'alt_inheritance_mode' => null,
                'alt_text_status' => filled($source->alt_text) ? Image::ALT_PENDING_REVIEW : null,
                'shopify_alt_pending' => false,
            ]);
        } elseif ($copy->alt_confirmation_media_id !== null && (string) $copy->alt_text !== (string) $source->alt_text) {
            $unavailable[] = 'Image '.$copy->id.': resolve its pending Shopify alt confirmation before replacing the local sentence.';
        }

        Image::withoutEvents(fn () => $copy->save());
        $kept[] = (int) $copy->id;

        // Keep provenance for components whose position-1 photographs share the same bytes.
        $this->closeStaleProvenanceAndRetarget($copy, collect($sources));
        foreach ($sources as $linked) {
            $key = [
                'image_id' => $copy->id,
                'component_image_id' => $linked->id,
                'image_asset_id' => $assetId,
            ];
            $previous = DB::table('inherited_image_sources')->where($key)->first();
            $snapshot = $this->provenanceSnapshot($linked, $linked->imageAsset);
            $values = ['component_product_id' => $linked->product_id, 'removed_at' => null] + $snapshot;
            if ($previous === null) {
                DB::table('inherited_image_sources')->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
            } else {
                $changed = array_filter($values, fn ($value, $field): bool =>
                    (string) $value !== (string) ($previous->{$field} ?? ''),
                    ARRAY_FILTER_USE_BOTH,
                );
                if ($changed !== []) {
                    app(ImagePhotographEvidence::class)->archiveSource($previous, 'restage_primary_source');
                    DB::table('inherited_image_sources')->where('id', $previous->id)->update($changed + ['updated_at' => now()]);
                }
            }
        }
    }

    foreach ($gallery as $image) {
        if (ImageOwnership::isInherited($image)
            && in_array((int) $image->id, $activeIds, true)
            && ! in_array((int) $image->id, $kept, true)) {
            Image::withoutEvents(fn () => $image->forceFill([
                'is_duplicate_hidden' => true,
                'duplicate_of_image_id' => null,
                'duplicate_hidden_at' => now(),
                'duplicate_hidden_reason' => self::OUTSIDE_PRIMARY_GALLERY,
            ])->save());
        }
    }

    $rebuilt = $kept !== [] || $unavailable === [];
    $message = $rebuilt
        ? "{$stack->handle}: rebuilt the local inherited gallery (".count($kept).' photograph(s)). Stack-owned images were left in place. Shopify was not updated.'
        : "{$stack->handle}: local rebuild failed. No photograph was copied.";
    if ($unavailable !== []) {
        $message .= ' Unavailable: '.implode(' ', $unavailable);
    }

    return ['rebuilt' => $rebuilt, 'message' => $message, 'created' => $kept, 'unavailable' => $unavailable];
}
```

The existing publisher has a separate helper named `stageComponentOnStack()`. Keep it for the legacy publication workflow. It is no longer used to build the local gallery. Leave automatic Shopify fan-out disabled for this local rollout.

## 4. `app/Services/StackImageSync.php`

Inside `plan()`, directly after the existing `if (! ImageOwnership::isInherited($image)) { continue; }` guard, add this guard. The planner reads all rows, including hidden ones, so it must not upload or retire a row hidden by the new local policy.

```php
if ($image->is_duplicate_hidden
    && $image->duplicate_hidden_reason === ComponentStackPropagation::OUTSIDE_PRIMARY_GALLERY) {
    continue;
}
```

Search for `stageStack(` and remove any remaining second argument passed by the old source preparation. The checked importer is the call site that supplied it. Search for the three removed positioning/retirement method names before deleting them. Keep the existing Shopify attachment/reorder checks and authorization.

## 5. Focused tests and rollout

Update `tests/Feature/StackImageRebuildSourceTest.php` and `tests/Feature/StackRebuildDefectTest.php`. Replace the assertions that expect Shopify-only fallback, secondary component selection, or automatic local deletion of obsolete rows. Those expectations describe the previous policy. Keep hash, idempotence, status, and claim tests. For Shopify-only fixtures, assert **zero gateway calls**, an incomplete result, and no inherited image for that component.

Add this regression to `StackImageRebuildSourceTest.php`, using its existing fake storage and gateway fixtures:

```php
it('rebuilds one local primary per component after two lifestyle photographs', function (): void {
    [$stack, $first, $missing, $last] = stackRebuildBundle('PRIMARYSTACK', ['PRIM-A', 'PRIM-B', 'PRIM-C']);

    Storage::disk('public')->put('component-photos/primary-a.jpg', 'primary-a-bytes');
    Storage::disk('public')->put('component-photos/primary-c.jpg', 'primary-c-bytes');
    $a = stackComponentPhotograph($first, [
        'image_path' => 'component-photos/primary-a.jpg',
        'shopify_id' => null,
        'alt_text' => 'Product A',
    ]);
    $c = stackComponentPhotograph($last, [
        'image_path' => 'component-photos/primary-c.jpg',
        'shopify_id' => null,
        'alt_text' => 'Product C',
    ]);
    foreach ([$first, $missing, $last] as $component) {
        $asset = createImportTestAsset('secondary-'.$component->id, 'jpg', 'secondary-'.$component->id.'.jpg');
        stackComponentPhotograph($component, [
            'position' => 2,
            'image_asset_id' => $asset->id,
            'alt_text' => 'Component lifestyle must never be inherited',
        ]);
    }

    $disk = Storage::disk('shopify_product_images');
    $disk->put('incoming/primary-stack/PRIMARYSTACK.jpg', 'stack lifestyle one');
    $disk->put('incoming/primary-stack/PRIMARYSTACK-1.jpg', 'stack lifestyle two');
    $batch = runStackImageImport('incoming/primary-stack');
    $order = fn () => $stack->allImages()->active()->orderBy('position')->get();
    $before = $order();

    expect($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($before->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($before->pluck('source_image_id')->all())->toBe([null, null, $a->id, $c->id])
        ->and($before[2]->alt_text)->toBe('Product A')
        ->and($before[3]->alt_text)->toBe('Product C');

    runStackImageImport('incoming/primary-stack');
    expect($order()->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});
```

Also cover two position-1 rows on a component, shared primary bytes, and a previously published secondary row. Expected results are an incomplete skip for ambiguous position 1, a single inherited copy for shared bytes, and local hide with the original Shopify ID intact. Validate direct **Rebuild locally** and the S3-triggered job. This handoff contains proposed code; it was reviewed against the workspace but was not applied or executed.

### Deploy separately from the queue fixes

This gallery patch adds no migration or environment flag. The current workspace contains separate, in-progress changes to claim ownership, batch ownership, and the forward schema migration. Keep those edits separate from the gallery patch. This handoff does not certify their deployment readiness.
