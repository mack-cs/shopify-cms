<x-filament-panels::page>
    <div class="mb-4 text-sm leading-6">
        <p>Local CMS rebuild and Shopify synchronization are separate. A completed local rebuild does not mean Shopify was updated.</p>
        <ul class="list-disc pl-5">
            <li>local_cms_rebuild_completed — the stack gallery was rebuilt in the CMS.</li>
            <li>local_rebuild_failed — the local rebuild did not finish. Shopify was not updated.</li>
            <li>shopify_update_pending_authorization — Shopify is waiting for approval, or the stack is draft or inactive.</li>
            <li>shopify_synchronization_queued — an authorized update is waiting for a worker.</li>
            <li>shopify_synchronization_in_progress — a worker is publishing the authorized difference.</li>
            <li>shopify_synchronization_verified — Shopify read-back matched the authorized versions.</li>
            <li>no_changes_required — Shopify already had the authorized photograph and alt text versions.</li>
            <li>failed_or_uncertain_synchronization — the remote result needs recovery before another mutation.</li>
        </ul>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
