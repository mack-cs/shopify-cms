<p>Direct inventory corrections in Shopify from {{ $fromDate }} through {{ $untilDate }}.</p>

<p>These are quantity corrections made in Shopify itself. Changes sent by this CMS are left out. Nothing in this email changed stock.</p>

@foreach ($groups as $group)
    <h2 style="font-size: 16px;">{{ $group['user'] }} ({{ count($group['rows']) }})</h2>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse;">
        <thead>
            <tr>
                <th align="left">When</th>
                <th align="left">SKU</th>
                <th align="left">Product</th>
                <th align="left">Change</th>
                <th align="left">App</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_slice($group['rows'], 0, 20) as $row)
                <tr>
                    <td>{{ $row['occurred_at'] }}</td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['product'] }}</td>
                    <td>{{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }} {{ $row['state'] }}</td>
                    <td>{{ $row['app'] !== '' ? $row['app'] : 'Shopify' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if (count($group['rows']) > 20)
        <p>{{ count($group['rows']) - 20 }} more correction(s) for {{ $group['user'] }} are not listed in this email.</p>
    @endif
@endforeach
