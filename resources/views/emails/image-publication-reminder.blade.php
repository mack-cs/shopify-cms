@php
    /** @var array<int, array<string, mixed>> $products */
@endphp
<p>{{ $kind === 'follow_up' ? 'Follow-up' : 'Reminder' }} for image and alt text changes that have been outstanding for more than 90 minutes.</p>
<p>{{ $instruction }}</p>
<ul>
@foreach ($products as $product)
    <li>
        <strong>{{ $product['sku'] }}</strong> — {{ $product['title'] }}<br>
        Status: {{ $product['status'] }}<br>
        Action: {{ implode('; ', $product['actions'] ?? []) }}
        @if (! empty($product['reason']))
            <br>Reason: {{ $product['reason'] }}
        @endif
    </li>
@endforeach
</ul>
