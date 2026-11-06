@php($prompt = $membershipPrompt ?? null)
@if (is_array($prompt) && ($prompt['gid'] ?? null) === ($sortGid ?? null))
    <section class="mt-3 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm dark:border-danger-700 dark:bg-danger-950" data-membership-prompt data-membership-collection="{{ $sortGid }}" role="alert" aria-label="Shopify membership for {{ $sortTitle }}">
        <h2 class="font-semibold text-gray-900 dark:text-white">Shopify membership has changed — {{ $prompt['title'] ?? $sortTitle }}</h2>
        <p class="mt-2">Sorting was not started. The saved draft was not replaced.</p>
        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
            <div><dt class="font-medium">Collection</dt><dd>{{ $prompt['title'] ?? $sortTitle }}</dd></div>
            <div>
                <dt class="font-medium">Last successful membership verification</dt>
                <dd>{{ filled($prompt['verified_at'] ?? null) ? \Illuminate\Support\Carbon::parse($prompt['verified_at'])->timezone(config('app.timezone'))->format('j M Y, H:i') : 'Not recorded' }}</dd>
            </div>
            <div><dt class="font-medium">Saved product count</dt><dd>{{ $prompt['saved_count'] ?? 0 }}</dd></div>
            <div><dt class="font-medium">Current Shopify product count</dt><dd>{{ $prompt['shopify_count'] ?? 0 }}</dd></div>
        </dl>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <h3 class="text-xs font-semibold">Products added in Shopify</h3>
                <ul class="mt-1 list-inside list-disc text-xs">
                    @forelse ($prompt['added'] ?? [] as $product)
                        <li>{{ $product['title'] }} ({{ $product['id'] }})</li>
                    @empty
                        <li>None</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <h3 class="text-xs font-semibold">Products removed from Shopify</h3>
                <ul class="mt-1 list-inside list-disc text-xs">
                    @forelse ($prompt['removed'] ?? [] as $product)
                        <li>{{ $product['title'] }} ({{ $product['id'] }})</li>
                    @empty
                        <li>None</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <x-filament::button type="button" size="sm" class="mt-3" wire:click="refreshMembershipPreservingSort({{ \Illuminate\Support\Js::from($sortGid) }})" wire:loading.attr="disabled" wire:target="refreshMembershipPreservingSort">
            <span wire:loading.remove wire:target="refreshMembershipPreservingSort">Refresh membership &amp; preserve sorting</span>
            <span wire:loading wire:target="refreshMembershipPreservingSort">Refreshing membership for {{ $sortTitle }}…</span>
        </x-filament::button>
    </section>
@endif
