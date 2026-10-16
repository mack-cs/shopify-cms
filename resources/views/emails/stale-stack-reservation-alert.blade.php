<p>The stack reservation check found {{ count($rows) }} leftover {{ \Illuminate\Support\Str::plural('row', count($rows)) }} that Shopify does not show as an open order.</p>

<p>Nothing was changed. These rows are not corrected automatically. Cancel each one yourself on the Stack Reservations page. Cancelling closes the reservation. It moves stock back to available only when live Shopify still holds that reserved quantity.</p>

<table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">Issue</th>
            <th align="left">Order</th>
            <th align="left">Shopify fulfillment</th>
            <th align="left">Component</th>
            <th align="left">Still reserved</th>
            <th align="left">Reservation</th>
        </tr>
    </thead>
    <tbody>
        @foreach (array_slice($rows, 0, 40) as $row)
            <tr>
                <td>{{ $row['issue'] }}</td>
                <td>{{ $row['shopify_order_name'] ?: $row['shopify_order_id'] }}</td>
                <td>{{ $row['shopify_fulfillment_status'] ?: 'Unknown' }}</td>
                <td>{{ $row['component_sku'] }} {{ $row['component_title'] }}</td>
                <td>{{ $row['remaining_reserved'] }}</td>
                <td>{{ $row['reservation_id'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if (count($rows) > 40)
    <p>{{ count($rows) - 40 }} more row(s) are not listed. Open Stack Reservations and scan the leftover CSV for the full list.</p>
@endif
