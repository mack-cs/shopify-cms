<div class="space-y-3 rounded-xl border border-gray-200 p-4" role="status">
    <p><strong>Editing scope: {{ $draft->desired['parent']['title'] ?? $draft->collection_gid }} and its linked vibe draft changes</strong></p>
    <p class="text-sm">Ordering, membership, vibe metadata and creation/removal share one saved draft. Other parent drafts remain available.</p>
    <p x-show="editingState.active" x-text="(editingState.owner || '') + ' is currently editing this collection. Their pending changes are protected.'">{{ $editing['owner'] }} is currently editing this collection.</p>
    <p x-show="editingState.active" x-data="{ clock: Date.now(), timer: null, init() { this.timer = setInterval(() => { this.clock = Date.now(); if (editingState.expires_at && Date.parse(editingState.expires_at) &lt;= this.clock) editingState.active = false; }, 1000); }, destroy() { clearInterval(this.timer); } }"
       x-text="'Lock remaining: ' + Math.max(0, Math.ceil((Date.parse(editingState.expires_at) - clock) / 1000)) + ' seconds'"></p>
    <p x-show="!editingState.active && editingState.pending" x-text="(editingState.pending_owner || 'The previous editor') + ' has pending changes. You can contact them or review and discard the changes if you have permission.'"></p>
    <p x-show="!editingState.active && !editingState.pending">No active editing lock. Start editing to reserve this draft for 14 minutes after your last editing activity.</p>
    <p x-show="editingState.pending" x-text="'Pending owner: ' + (editingState.pending_owner || 'Unknown legacy editor') + '. Last modified: ' + (editingState.changed_at || '') + '. Revision ' + (editingState.revision || {{ $draft->revision }})"></p>
    <div class="flex flex-wrap gap-3">
        <x-filament::button color="gray" wire:click="startEditing" x-bind:disabled="editingState.blocked" wire:loading.attr="disabled">Start editing / reacquire lock</x-filament::button>
        <x-filament::button color="gray" wire:click="reviewDiscard" x-bind:disabled="!editingState.pending || (editingState.active && !editingState.mine)" wire:loading.attr="disabled">Review and discard pending changes</x-filament::button>
        <x-filament::button color="gray" wire:click="$toggle('showEditingHistory')">Change history</x-filament::button>
    </div>
    @if ($editing['last_discard_id'])
        <p>Discarded by {{ $editing['last_discard_by'] }}. History record #{{ $editing['last_discard_id'] }} is available in Change history.</p>
    @endif
</div>
@if ($confirmingDiscard)
    <section x-data="{ discardText: '' }" class="space-y-3 rounded-xl border border-warning-300 p-4" aria-label="Review pending discard">
        <h3 class="font-semibold">Discard this parent draft’s pending changes</h3>
        <p>Collection: {{ $draft->desired['parent']['title'] ?? $draft->collection_gid }}. Original editor: {{ $editing['pending_owner'] }}.</p>
        <p>Changed: {{ $editing['changed_at'] ?? $draft->updated_at }}. Pending revision: {{ $draft->revision }}.</p>
        <ul>@foreach ($editing['changes'] as $change)<li>{{ $change['type'] }} — {{ $change['target'] }} — {{ $change['at'] }}</li>@endforeach</ul>
        <p>All pending ordering, membership and metadata in this shared parent draft will be restored to its last confirmed Shopify state. Other parent drafts are unchanged. Shopify will not be contacted.</p>
        <dl>@foreach ($discardSummary as $label => $value)<div>{{ $label }}: {{ $value }}</div>@endforeach</dl>
        <label for="syv-discard-reason">Reason (required; at least 10 letters or numbers)</label>
        <textarea id="syv-discard-reason" x-model="discardText" wire:model="discardReason" required minlength="10" maxlength="4000" class="w-full rounded border-gray-300"></textarea>
        <p class="text-sm" x-text="'Reason: ' + discardText.replace(/[^\p{L}\p{N}]/gu, '').length + '/10 letters or numbers required.'"></p>
        <p x-show="discardText.replace(/[^\p{L}\p{N}]/gu, '').length &lt; 10" class="text-sm text-danger-600">Enter at least 10 letters or numbers to enable Confirm. For example: No longer needed.</p>
        @error('discardReason')<p role="alert">{{ $message }}</p>@enderror
        @if ($discardError)<p role="alert" class="text-danger-600">{{ $discardError }}</p>@endif
        <x-filament::button color="danger" wire:click="discardChanges" x-bind:disabled="discardText.replace(/[^\p{L}\p{N}]/gu, '').length &lt; 10" :disabled="$editing['active'] && ! $editing['mine']" wire:loading.attr="disabled">Confirm local discard</x-filament::button>
        <x-filament::button type="button" color="gray" wire:click="cancelDiscard" wire:loading.attr="disabled" wire:target="cancelDiscard">Cancel</x-filament::button>
    </section>
@endif
@if ($showEditingHistory)
    <section class="space-y-3 rounded-xl border border-gray-200 p-4" aria-label="Permanent discard history">
        <h3 class="font-semibold">Permanent discard history</h3>
        @forelse ($editingHistory as $entry)
            <details><summary>Record #{{ $entry->id }} — {{ $entry->created_at }}</summary><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode(json_decode($entry->old_value, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode(json_decode($entry->new_value, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        @empty
            <p>No discards recorded for this draft.</p>
        @endforelse
    </section>
@endif
