# Shop Your Vibe

The Catalog → Shop Your Vibe page manages collection previews. Existing collection and product catalogues provide searchable selectors; workflow snapshots are stored separately in `shop_your_vibe_drafts`. Access follows the existing Admin/SuperAdmin roles, or requires both `product.edit` and `shopify.push_products`.

## Deployment

Run the new migration and start a supervised worker for the dedicated queue:

```sh
php artisan migrate
php artisan queue:work shop-your-vibe --queue=shop-your-vibe --timeout=840 --tries=1
```

The dedicated database queue uses the existing jobs table with a 960-second reservation. Its worker must run separately from the default queue, whose 90-second reservation is too short for paginated Shopify pushes. Restart workers and rebuild any cached configuration after deployment. Without this worker, the editor saves drafts but pushes remain queued.

The Shopify app needs existing collection/product read/write access, metaobject definition read access, and metaobject read/write access. The image chooser requires `read_files`; new image uploads also require `write_files`. New collection publication requires `read_publications` and `write_publications`. Reauthorize the app if these scopes are newly enabled. Creation uses [collectionCreate](https://shopify.dev/docs/api/admin-graphql/2026-01/mutations/collectionCreate), followed by [publishablePublish](https://shopify.dev/docs/api/admin-graphql/latest/mutations/publishablepublish) for the Online Store publication only.

Uploaded images are saved under `storage/app/public/shop-your-vibe` until the worker sends them through Shopify staged uploads and `fileCreate`. Keep this storage available to the web process and queue worker. Run `php artisan storage:link` for CMS image previews. Uploads accept JPEG, PNG and WebP up to 10 MB; PHP and web-server upload limits must allow that size. The worker does not depend on Shopify being able to fetch the CMS preview URL.

## Verified Shopify structure

Read-only inspection on 8 September 2026 confirmed `shop_your_vibe_card_preview` has exactly these fields:

| Handle | Shopify type |
| --- | --- |
| `image` | `file_reference` |
| `name` | `single_line_text_field` |
| `link` | `url` |

There is no collection-reference field. Collection links resolve through their storefront URL to an existing collection GID. CMS catalogue handles are used for lookup only; collection and product writes use GIDs. New preview objects use a persisted UUID-based handle for idempotent upsert; subsequent updates use the returned metaobject GID.

The parent collection field is exclusively `custom.shop_your_vibe_preview`, a `list.metaobject_reference`. The live field is never queried or mutated. Shopify-side promotion of preview data is outside this workflow.

The inspected Bracelets, Necklaces and Earrings parents had 13, 4 and 4 cards respectively. Sample linked collections were automated and manually sorted: manual order is supported, but adding/removing membership is controlled by Shopify rules and is disabled in the editor.

## Draft and push behavior

- New vibe metaobjects are automatically created as **ACTIVE** during the push when the definition supports draft/active statuses. The push checks Shopify's returned status before attaching the card, and retries reuse the same UUID handle. Existing entries are not bulk-activated by this change.
- **Add Vibe** offers a searchable existing collection selector or **Create a new collection**. Enter a title, edit the automatically generated handle if needed, and optionally upload an image or select a ready Shopify image. The image is used for both the collection and its preview card.
- New collections are manual collections. They support adding/removing products and sorting in the local draft before creation. Confirming **Push Changes** creates them with their handles and images, applies the saved product order, then publishes them to the live Online Store. The review explicitly lists new collections to publish; the vibe layout itself still targets the preview metafield.
- New collection creation is checkpointed with a UUID token in `custom.syv_creation_token`. A retry looks up the requested handle and recovers only a collection bearing that token, rejecting unrelated handle collisions. Uploaded files use stable UUID filenames with duplicate rejection; file IDs are saved before waiting for Shopify image processing. A failed image or publication remains pending for retry. Created collections become searchable in the CMS catalogue.
- Dragging, product selections, removals and saving card fields update the database only. Field inputs show an immediate unsaved warning; **Save card to draft** persists those fields. Navigation warns about unpublished changes.
- Opening a vibe loads its products and current sorting mode with read-only Shopify requests. Shopify image searches also make read-only requests.
- **Enable Manual Sorting** requires confirmation and stages the change until the final push. Dragging never enables manual sorting implicitly.
- **Push Changes to Shopify** opens a change summary. Confirming it queues the saved draft, pauses editing, and writes only its final differences.
- Pushes serialize to avoid competing changes to shared cards/collections. Stale editor revisions and changed Shopify snapshots are rejected. Parent metafield writes include Shopify's compare digest.
- Successful operations are checkpointed. Failed or unfinished work remains pending, with operation progress and an error message. Async membership/order jobs are stored and confirmed before retrying; pending jobs block editing and discard until their state is resolved by retry.
- Removed cards are detached. No products, collections or underlying metaobjects are deleted.
- Refresh and discard read Shopify again, warn before replacing a draft, and never undo already pushed changes.
- Collection product changes affect the represented collection wherever Shopify uses it. Editing a shared preview object also affects other preview layouts referencing it.

## Validation

```sh
php vendor/bin/pest tests/Feature/ShopYourVibeWorkflowTest.php tests/Feature/ShopYourVibeThrottleTest.php
php vendor/bin/pest
```

Tests use an in-memory database and a stateful Shopify fake. They cover the actual preview fields and reference ordering, persistent local edits without API traffic, explicit manual sorting, automated membership restrictions, additions/removals, duplicate prevention, successful/failed/partial pushes, asynchronous job confirmation, conflict protection, access control, refresh confirmation, and throttling retries. Read-only smoke checks also verified the implemented definition, parent, linked products and image queries against Shopify; no mutations were sent to the real store during implementation.

The full suite was run with `php -d memory_limit=1G vendor/bin/pest` because the runtime's default 128 MB limit was insufficient. It completed with 298 passing tests and 36 failures in existing test areas (including missing authentication routes, SQLite-incompatible queries, catalogue normalization, product defaults and complementary product expectations). Output is saved locally at `storage/logs/shop-your-vibe-test-suite.log`. Those unrelated application behaviors were not changed by this implementation.
