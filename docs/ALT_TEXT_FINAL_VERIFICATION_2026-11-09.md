**Leigh Avenue CMS — final alt text verification, 9 November 2026**

**Overall: FAIL. Production backfill workflow is not yet cleared.**

Verification used the actual Laravel services with an in-memory SQLite database, fake storage, mocked GraphQL gateway responses, and mocked HTTP responses for media uploads. No production database inspection, production import, live Shopify mutation, or live stack rebuild was performed. PASS below means verified in this isolated environment, not observed in the production Shopify store. Existing implementation changes were retained; this review added tests and this report only.

Final result: **29 passed, 5 failed, 162 assertions** across `ImageAltTextBackfillTest`, `ShopifyImageImportTest`, and `ProductImageSyncSourceTest`. The five failures are regression expectations for defects, intentionally left failing for the fixes to address.

Reproduce from the repository root:

```powershell
& 'C:\Users\shona\.config\herd\bin\php84\php.exe' vendor/pestphp/pest/bin/pest tests/Feature/ImageAltTextBackfillTest.php tests/Feature/ShopifyImageImportTest.php tests/Feature/ProductImageSyncSourceTest.php --compact --colors=never
```

**Requirement results**

| Requirement | Result | Evidence / limitation |
|---|---|---|
| 1: No collection selected exports all active products | PASS | Exact image-ID set checked, including outside-collection active products. This is the local CMS active catalog. |
| 1: Collection A only | PASS | Real membership service, mocked Shopify connection, exact image-ID set. |
| 1: Collection B only | PASS | Same independent check for B. |
| 1: A+B union without duplicates | PASS | Overlapping stack belongs to both, exported once. |
| 1: Inactive and outside products excluded | PASS | Local draft excluded even when Shopify fixture says ACTIVE; Shopify ARCHIVED node excluded by membership service; outside product excluded. |
| 1: Membership comes from Shopify, not tags | PASS | Service queries collection.products; outside product with matching collection tags remains excluded. |
| 1: Complete, reliable pagination for large collections | FAIL | Normal 100-node first page plus next page works. Missing continuation cursor silently truncates; repeated cursor has no guard. Production-scale completeness/performance not established. |
| 1: ZIP and CSV use exactly the same filters | PASS | Each selection checked against standalone CSV, ZIP embedded CSV, and exact archive image filenames. |
| 2: Only stack-owned position 1 is editable | PASS for ordinary positions | Position 2 legacy row and position 3 linked row excluded; legacy position 2 import also rejected. See invalid-position caveat below. |
| 2: Normal/component products export all eligible images | PASS | Component has two independently exported images. Hidden and deleted images excluded. |
| 2: Several components / legacy copies / mixed collections / overlapping collections | PASS | Stack links components from A and B; selected-collection dependencies remain outside the main CSV and ZIP images; overlap produces no duplicates. Legacy copies are exceptions, not editable rows. |
| 2: Components outside selection not silently included | PASS | Dependency exception emitted; component image excluded. |
| 3.1: Rebuild preserves image 1 and approved alt | PASS for valid stack | Original row/media ID and approved alt retained in fixture with genuine position 1. Missing-position-1 case fails under requirement 4. |
| 3.2: Component copies ordered correctly | PASS | Reverse component linkage order used to distinguish component order from database creation order. One selected Shopify image per component, positions 2 and 3. |
| 3.3: Alt comes from exact selected component image | PASS | Two images per component with distinct alt; first selected image's alt copied, second image's alt never used. |
| 3.4: Correct source_image_id stored | PASS | Exact ordered source IDs asserted. |
| 3.5: Copied alt included in Shopify upload | PASS | Actual ProductShopifyUpdater upload path invoked with mocked HTTP; captured productCreateMedia input contains the copied alt. Blank source alt omits alt. |
| 3.6: Subsequent metadata-only propagation to valid copies | PASS for valid links | Newly uploaded copy ID receives fileUpdate with only id and alt. Stale-link safety fails under requirement 5. |
| 3: No product-title fallback | PASS | Blank source remains null; nonblank source comes from image, not title. |
| 4: Legacy rebuild establishes current source and alt | PASS for complete, valid stack | Unlinked legacy copy marked locally deleted, replacement copies linked to exact current source images. |
| 4: Stack image 1 and description preserved | PASS for valid stack | Image 1 ID/alt retained; all product attributes unchanged by private image rebuild method. Public batch action also updates compare-at price separately. |
| 4: Unsafe legacy rebuild protection | FAIL | Missing position 1 promotes position 2. A component without images is silently skipped and partial rebuild proceeds. |
| 4: Report actual unsafe production stacks | FAIL — not established | No production catalog inspected; actual SKU/ID inventory cannot be supplied from isolated fixtures. Unsafe categories listed below. |
| 4: No production rebuilds during verification | PASS | Only disposable in-memory fixture rebuilds. |
| 5: Import uses stable image IDs | PASS | Local image ID controls target; supplied position does not redirect update. Optional product/media identity mismatch checks also exist in code. |
| 5: Blank alt skipped | PASS | Blank row leaves existing text unchanged. |
| 5: Unchanged and repeated import skipped | PASS | Second identical successful import sends no additional mutation. |
| 5: Stack positions 2+ cannot independently import | PASS | Both linked and unlinked legacy rows rejected. |
| 5: Component changes propagate only to verified copies | FAIL | Valid links work, but stale links to a source outside current component list are also updated. |
| 5: fileUpdate preserves photograph | PASS at request level | Captured input contains only id and alt, without source replacement fields. Live photograph preservation not observed. |
| 5: Failed updates retry | PASS for explicit errors | User errors set pending state; repeat import and dedicated retryPending both succeed with mocked recovery. |
| 5: Unconfirmed response remains retryable | FAIL | Empty mutation response is accepted as success, so pending can be cleared without confirmation. |
| 5: Dry run makes no writes | PASS in service test | No alt change and zero gateway calls; apply=false exits before storeAlt/pushAlt. |
| 6: Complete production workflow supported | FAIL | Fix the defects and close the production inventory/scale gaps before approval. |

**Five reproduced defects and required fixes**

1. `app/Services/ShopifyImageImportService.php`, rebuildStackImages / firstActiveImage: selecting the first available image does not establish that it is the stack-owned image at position 1. A stack containing only position 2 is rebuilt successfully and that row becomes position 1. Require a genuine owned first image; reject missing or ambiguous ownership before mutation.
2. Same file, component source gathering: missing component images or unavailable assets cause `continue`. If one other component is usable, existing legacy rows are removed and only a partial set is recreated. Missing component product IDs are also omitted by the whereIn lookup. Preflight every linked component and its chosen image/asset; abort with a complete reason list before replacing anything if any dependency is unresolved.
3. `app/Services/ImageAltTextBackfillService.php`, verifiedCopies: filters only active image rows by source_image_id. It does not validate that the target is an inherited stack row or that the source's product remains a current linked component. Export already calls such a link stale, but import still changes it. Share source validation between export, propagation, and retry; report and leave invalid copies untouched.
4. `app/Services/ShopifyCollectionMembership.php`, activeProductGids: hasNextPage=true with missing/empty endCursor exits successfully with an incomplete set. Repeated cursor can loop indefinitely. Validate products/nodes/pageInfo structure, require a new cursor for every continuation, and throw a visible error rather than export a partial result. Test malformed and repeated cursors. ShopifyApiClient already retries ordinary query throttling, but that does not fix response-shape validation.
5. `app/Services/ImageAltTextShopifyWriter.php`, update: missing fileUpdate/userErrors defaults to an empty error list and counts as success. Require a valid payload and confirmed returned file; retain pending state for missing/null/malformed results. Add returned ID to the selection and check the expected ID/alt where appropriate.

**Additional gaps and operating constraints**

- Collection membership is read live, but export intersects it with local Product records and local active status. Products not imported into the CMS, or locally stale/inactive products, are absent. No-collection export uses only local status. Full Shopify catalog completeness requires a current catalog sync/reconciliation.
- Collection GIDs and CSV rows are accumulated in memory, and ZIP photographs are downloaded synchronously, one request at a time with a 30-second timeout each. The pagination test crosses the 100-node page boundary; it is not a production-sized load test. Consider a background export job and chunked processing for large exports.
- ZIP uses the same row selection, but failed photograph downloads are silently omitted while the manifest still lists them. Filter parity passes; guaranteed photograph delivery does not. Record download failures visibly and provide a retry/reconciliation mechanism before relying on every file being present.
- Stack export treats null or <=1 positions as potential owned first images; import also allows unlinked null/zero-position stack rows. Strict position-1-only behavior for malformed legacy data is therefore not enforced. Normalize or reject invalid positions, and use one consistent owned-image rule.
- Successful rebuild may rename image 1 and update its managed URL/sync flags through renameActiveProductImagesFromHandle. The tested valid fixture preserves photograph identity and approved alt, but rebuild is not a promise of unchanged filename/URL/sync state. If image 1 must remain wholly untouched, exclude it from those post-processing steps.
- Components sharing the same image asset are deduplicated during rebuild. The first component's source wins. Review whether that is intended when identical photographs have different approved alt text.
- The CSV parser splits input into physical lines before parsing CSV. Quoted alt text with embedded newlines cannot round-trip safely. Use stream-based fgetcsv parsing if such text is permitted.
- Actual unsafe legacy stacks must be identified in a read-only production inventory before rebuilding: missing/ambiguous owned first image; missing component draft/product; component without eligible Shopify image or retrievable managed asset; stale component linkage; shared-asset ambiguity. No actual production stack is certified safe by this fixture run.
- A local rebuild explicitly leaves Shopify image sync pending for approval. The proposed workflow needs an **approve/apply stack image sync** step between rebuilding and checking Shopify, unless an established separate process already performs it. The public rebuild action also recalculates compare-at prices; account for this side effect when choosing a production rebuild route.

Shopify's documented FileUpdateInput separates alt from originalSource and previewImageSource, supporting metadata-only updates: https://shopify.dev/docs/api/admin-graphql/2026-01/input-objects/FileUpdateInput . Actual account access scopes and live results remain unverified.

**Delivery decision:** Do not yet approve the proposed production workflow. Resolve the five reproduced failures, review the additional constraints, run the same suite successfully, inventory unsafe production legacy stacks read-only, and verify on a disposable staging stack before production use. No application implementation fixes were applied in this verification.
