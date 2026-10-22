<div id="syv-status-banner" x-ref="statusBanner" role="status" class="syv-load">
    <div class="syv-load-heading">
        <span class="syv-spinner" aria-hidden="true"></span>
        <strong>{{ $title }}</strong>
    </div>
    <p class="syv-load-copy">{{ $detail }}</p>
    <div class="syv-load-meta">
        <span data-status-stage>{{ $stage }}</span>
        <span data-status-count>{{ $count }}</span>
    </div>
    <progress class="syv-progress" data-status-progress value="{{ $done }}" max="{{ max(1, $total) }}" @if ($total < 1) hidden @endif></progress>
    <div class="syv-indeterminate" data-status-indeterminate @if ($total > 0) hidden @endif aria-hidden="true"></div>
    <div data-status-stale hidden>
        <p>Loading is taking longer than expected.</p>
        <div class="mt-3"><x-filament::button size="sm" wire:click="retryHydration" wire:loading.attr="disabled">Retry</x-filament::button></div>
    </div>
</div>
