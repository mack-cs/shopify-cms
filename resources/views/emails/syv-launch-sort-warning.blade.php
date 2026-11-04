<p>{{ $details['product_name'] }} is Active and published, and Shop Your Vibe sorting is still open.</p>

<ul>
    <li>Product: {{ $details['product_name'] }}</li>
    <li>SKU: {{ $details['sku'] !== '' ? $details['sku'] : 'No SKU' }}</li>
    <li>Launched by: {{ $details['launch_actor'] }}</li>
    <li>Launch time: {{ $details['launch_at'] }}</li>
</ul>

<p>Outstanding collections:</p>
<ul>
    @foreach ($details['collections'] as $collection)
        <li>
            {{ $collection['name'] }} — {{ $collection['status'] }}
            — <a href="{{ $collection['url'] }}">Open in Shop Your Vibe</a>
        </li>
    @endforeach
</ul>

<p>The product launch was not blocked, and this email does not publish the product again.</p>
