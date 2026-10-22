<div class="syv-card-tools">
    @if ($canSort)
        <button type="button" x-sortable-handle x-bind:disabled="savingOrder" class="syv-drag-handle syv-drag-handle-icon" aria-label="Drag {{ $title }}">
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false">
                <circle cx="4" cy="2.5" r="1.15" fill="currentColor"></circle>
                <circle cx="10" cy="2.5" r="1.15" fill="currentColor"></circle>
                <circle cx="4" cy="7" r="1.15" fill="currentColor"></circle>
                <circle cx="10" cy="7" r="1.15" fill="currentColor"></circle>
                <circle cx="4" cy="11.5" r="1.15" fill="currentColor"></circle>
                <circle cx="10" cy="11.5" r="1.15" fill="currentColor"></circle>
            </svg>
        </button>
    @endif
    <label class="syv-position">
        <span>Position</span>
        <input type="number" inputmode="numeric" min="1" max="{{ $max }}" value="{{ $position }}" data-position-input x-on:keydown.enter.prevent="commitPosition($event.target)" x-on:change="commitPosition($event.target)" aria-label="Position of {{ $title }}">
    </label>
</div>
