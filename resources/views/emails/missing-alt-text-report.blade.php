<p>{{ count($products) }} active {{ \Illuminate\Support\Str::plural('product', count($products)) }} need image alt text.</p>

<p>This monthly report includes active products only.</p>

<table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">Product</th>
            <th align="left">Handle</th>
            <th align="left">Images missing alt text</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($products as $product)
            <tr>
                <td>{{ $product['title'] }}</td>
                <td>{{ $product['handle'] }}</td>
                <td>{{ $product['missing_image_alt_text_count'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
