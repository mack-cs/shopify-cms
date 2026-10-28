@php
    $source = \App\Models\Variant::query()->where('shopify_id', $family->source_variant_id)->first();
    $reserved = \App\Models\ShopifyStackInventoryReservation::query()->where('bead_family_id', $family->id)
        ->selectRaw('COALESCE(SUM(reserved_quantity - consumed_quantity - released_quantity), 0) as outstanding')->value('outstanding');
@endphp
<div class="space-y-4">
    <p>Physical stock is counted only on the tracked 1-bead source. Inventory writes require verified store configuration and explicit activation.</p>
    <p>Source on hand: {{ $source?->current_on_hand_quantity ?? 'Unknown' }};
        available: {{ $source?->current_available_quantity ?? 'Unknown' }};
        outstanding CMS reservations: {{ $reserved }} beads.
        These are synchronized snapshots, not a live stock confirmation.</p>
    @foreach ($family->validation_errors ?? [] as $error)
        <p class="text-danger-600">{{ $error }}</p>
    @endforeach
    <table class="w-full text-sm">
        <thead><tr><th>Beads</th><th>SKU</th><th>Variant</th><th>Threshold ≤</th><th>Tracked</th><th>Sellable</th></tr></thead>
        <tbody>
        @foreach ($family->members as $member)
            <tr><td>{{ $member->required_beads }}</td><td>{{ $member->sku }}</td><td>{{ $member->shopify_variant_id }}</td>
                <td>{{ $member->disable_threshold }}</td><td>{{ $member->last_verified_tracked === null ? 'Unknown' : ($member->last_verified_tracked ? 'Yes' : 'No') }}</td>
                <td>{{ $member->last_verified_sellable === null ? 'Unknown' : ($member->last_verified_sellable ? 'Yes' : 'No') }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($family->members->isEmpty())
        <p>Membership has not passed validation. Observed catalog:</p>
        <pre class="overflow-auto text-xs">{{ json_encode($family->observed_mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @endif
    <h3>Recent audit history</h3>
    @foreach ($family->audits()->latest('id')->limit(50)->get() as $audit)
        <p>{{ $audit->created_at }} · {{ $audit->action }} · actor {{ $audit->user_id ?? 'system' }}</p>
        <pre class="overflow-auto text-xs">{{ json_encode($audit->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @endforeach
</div>
