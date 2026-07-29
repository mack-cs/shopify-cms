<p>The daily SKU audit found {{ count($conflicts) }} SKU {{ \Illuminate\Support\Str::plural('conflict', count($conflicts)) }} that need attention.</p>

<p>A SKU should identify one product. Please check the products below and correct the duplicate assignment in Shopify Editor or Shopify. This audit is read-only and does not change any product or inventory data.</p>

<table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">SKU</th>
            <th align="left">Products using it</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($conflicts as $conflict)
            <tr>
                <td><strong>{{ $conflict['sku'] }}</strong></td>
                <td>
                    <ul>
                        @foreach ($conflict['products'] as $product)
                            <li>
                                {{ $product['title'] }}
                                @if ($product['handle'] !== '')
                                    ({{ $product['handle'] }})
                                @endif
                                — status: {{ $product['status'] }};
                                product ID: {{ $product['id'] }};
                                variant IDs: {{ implode(', ', $product['variant_ids']) }}
                            </li>
                        @endforeach
                    </ul>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
