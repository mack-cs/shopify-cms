# Delete Workflow

## Summary

This project now has a separate delete approval workflow for products and collections.

It is intentionally separate from the normal content approval workflow used for product and collection updates.

The delete workflow is built to:

- require 2 separate user approvals
- keep an immutable audit trail in the app
- delete in Shopify and locally
- support both products and collections

## New Tables

- `deletion_requests`
- `deletion_request_approvals`

## Files Added

- `database/migrations/2026_04_22_150000_create_deletion_requests_table.php`
- `database/migrations/2026_04_22_150100_create_deletion_request_approvals_table.php`
- `app/Models/DeletionRequest.php`
- `app/Models/DeletionRequestApproval.php`
- `app/Services/DeletionRequestWorkflowService.php`
- `app/Services/ShopifyDeletionService.php`
- `app/Jobs/ProcessDeletionRequestJob.php`

## Files Updated

- `app/Models/Product.php`
- `app/Models/ShopifyCollection.php`
- `app/Filament/Resources/ProductResource.php`
- `app/Filament/Resources/ProductResource/Pages/EditProduct.php`
- `app/Filament/Resources/ShopifyCollectionResource.php`
- `app/Filament/Resources/ShopifyCollectionResource/Pages/EditShopifyCollection.php`

## Workflow

1. A user clicks `Request Delete` on a product or collection.
2. A row is created in `deletion_requests`.
3. The requesting user is immediately recorded as approval `1/2`.
4. A second user clicks `Approve Delete`.
5. A second approval row is created in `deletion_request_approvals`.
6. Once approvals reach `2/2`, the request status changes to `processing`.
7. A background job is queued.
8. The job deletes the record in Shopify first.
9. If Shopify deletion succeeds, the record is deleted locally.
10. The request is marked `completed`.

## Statuses

`deletion_requests.status` uses:

- `pending`
- `processing`
- `completed`
- `failed`

## Audit Trail

The delete workflow writes immutable records to `change_logs`.

Logged fields:

- `deletion_requested`
- `deletion_approved`
- `deletion_completed`
- `deletion_failed`

The JSON payload stored in `new_value` includes items such as:

- `status`
- `reason`
- `entity_type`
- `title`
- `handle`
- `shopify_id`
- `approvals`
- `message` on failure

## UI Behavior

Both Products and Collections now expose:

- `Request Delete`
- `Approve Delete`

The tables also show a delete-request badge such as:

- `None`
- `Pending 1/2`
- `Pending 2/2`
- `Processing`

Important rules:

- a second delete request cannot be opened while one is already pending or processing
- the second approver must be a different user
- delete approval is separate from the normal edit/content approval flow

## Shopify Operations

The app uses Shopify Admin GraphQL delete mutations:

- `productDelete`
- `collectionDelete`

References:

- https://shopify.dev/docs/api/admin-graphql/latest/mutations/productDelete
- https://shopify.dev/docs/api/admin-graphql/latest/mutations/collectionDelete

For products, Shopify removes the product and its related Shopify product data.

For collections, Shopify removes the collection.

## Local Cleanup

For products, local cleanup also removes import snapshot data tied to the handle:

- `shopify_rows`
- `shopify_metafields`

Then the local product record is deleted. Related local rows already linked with cascading foreign keys are removed by the database.

For collections, the local collection record is deleted after Shopify deletion succeeds.

## Failure Handling

If Shopify deletion fails:

- the request is marked `failed`
- the error is stored in `failure_message`
- a `deletion_failed` entry is written to `change_logs`
- approvers receive a failure notification

## Main Logic

Delete request creation and approval:

- `app/Services/DeletionRequestWorkflowService.php`

Shopify deletion:

- `app/Services/ShopifyDeletionService.php`

Queued execution and local cleanup:

- `app/Jobs/ProcessDeletionRequestJob.php`

## Apply Steps

1. Run `php artisan migrate`
2. Make sure the queue worker is running
3. Open a product or collection
4. Click `Request Delete`
5. Log in as a second user and click `Approve Delete`
6. Confirm the Shopify record is gone
7. Confirm the local record is gone
8. Confirm `change_logs` contains the delete audit entries
