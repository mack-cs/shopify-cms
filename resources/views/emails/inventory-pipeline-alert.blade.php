<!doctype html>
<html lang="en"><body>
<h1>Inventory pipeline: {{ $type }}</h1>
<table cellpadding="6" style="border-collapse:collapse;text-align:left">
@foreach ($details as $label => $value)
    <tr><th scope="row">{{ ucwords(str_replace('_', ' ', $label)) }}</th>
    <td>@if ($label === 'cms_url' && $value)<a href="{{ $value }}">Open CMS records</a>@else{{ is_array($value) ? implode(', ', $value) : ($value ?? 'Unavailable') }}@endif</td></tr>
@endforeach
</table>
<p>Investigate the order webhook, all component snapshots, fulfillment payload, movement event keys and Shopify adjustment confirmations. Compare CMS counters with Shopify at the recorded inventory item and location. An unconfirmed remote response may already have changed Shopify inventory; reconcile it before any manual retry.</p>
<p>This monitor does not retry, cancel, release, delete or repair inventory. Recovery means the monitored condition cleared; it is not proof of a physical stock reconciliation.</p>
</body></html>
