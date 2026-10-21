<div class="space-y-3 text-sm">
    @if ($row['flag_text'] !== '')
        <p class="whitespace-pre-line text-amber-700 dark:text-amber-300">{{ $row['flag_text'] }}</p>
    @endif
    @if ($row['detail_text'] !== '')
        <dl class="whitespace-pre-line">{{ $row['detail_text'] }}</dl>
    @endif
    @if ($row['states_text'] !== '')
        <div>
            <div class="text-xs uppercase tracking-wide text-gray-500">State changes</div>
            <p class="whitespace-pre-line">{{ $row['states_text'] }}</p>
        </div>
    @endif
    <p class="text-xs text-gray-500">Saving a review status does not change Shopify inventory.</p>
</div>
