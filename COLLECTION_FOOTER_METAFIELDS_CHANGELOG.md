# Collection Footer Metafields Change Log

## Summary

This change adds the two new Shopify collection metafields to the existing collection workflow in this project:

- `custom.footer_description` mapped locally as `footer_title`
- `custom.elegant_footer_description` mapped locally as `elegant_footer_description`

The first Shopify key looks mismatched in the screenshot because the definition name says `Footer Title` while the key is `footer_description`. The code now follows the actual Shopify key so reads and writes stay correct.

## What Changed

1. Added local database columns for live and draft values.
2. Updated Shopify collection importers to pull both metafields into the collections table.
3. Updated the Filament collection editor so users can:
   - see the current Shopify values
   - edit draft values
   - approve draft values
   - push approved values back to Shopify
4. Updated the collection CSV import/export flow so these values can be updated from file.
5. Extended the Shopify collection push job to send these metafields with the rest of the approved collection payload.

## Files Changed

- `database/migrations/2026_04_22_120000_add_footer_metafields_to_collections_table.php`
- `app/Models/ShopifyCollection.php`
- `app/Services/ShopifyCollectionsImporter.php`
- `app/Services/ShopifyApiImporter.php`
- `app/Services/ShopifyCollectionUpdater.php`
- `app/Jobs/ShopifyCollectionUpdateJob.php`
- `app/Services/ShopifyCollectionSeoImporter.php`
- `app/Filament/Exports/ShopifyCollectionExporter.php`
- `app/Filament/Resources/ShopifyCollectionResource.php`

## Data Model Added

Live fields:

- `footer_title`
- `elegant_footer_description`

Draft fields:

- `draft_footer_title`
- `draft_elegant_footer_description`

## Workflow After This Change

1. Run collection sync.
   The importer now reads both Shopify metafields into the local collection record.
2. Edit draft values in the Collections screen or import them from CSV.
3. Approve the collection as before.
4. When the second approval lands, draft footer values are copied into the live collection fields.
5. Use `Push to Shopify` and include the footer metafield fields.
6. The background job sends:
   - `custom.footer_description`
   - `custom.elegant_footer_description`

## CSV Headers Supported

The collection SEO CSV importer now accepts these headers for the new draft fields:

- `footer_title`
- `footer title`
- `footer_description`
- `footer description`
- `elegant_footer_description`
- `elegant footer description`

These are written to:

- `draft_footer_title`
- `draft_elegant_footer_description`

## Push Mapping

Local to Shopify mapping:

- `footer_title` -> `custom.footer_description`
- `elegant_footer_description` -> `custom.elegant_footer_description`

## Code Notes

The updater now uses `metafieldsSet` for both collection footer fields in the same way it already handled the deindex metafield.

## Implementation Snippet

```php
$metafields[] = [
    'ownerId' => $collection->shopify_id,
    'namespace' => 'custom',
    'key' => 'footer_description',
    'type' => 'single_line_text_field',
    'value' => $this->metafieldStringValue($fields['footer_title']),
];
```

```php
$metafields[] = [
    'ownerId' => $collection->shopify_id,
    'namespace' => 'custom',
    'key' => 'elegant_footer_description',
    'type' => 'single_line_text_field',
    'value' => $this->metafieldStringValue($fields['elegant_footer_description']),
];
```

## Apply Steps

1. Run `php artisan migrate`
2. Run the collection sync to pull current Shopify values
3. Import or edit draft values
4. Approve
5. Push approved collections to Shopify

## Related Docs

Delete workflow documentation has been split into:

- `DELETE_WORKFLOW.md`
