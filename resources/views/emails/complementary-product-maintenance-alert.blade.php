<p>The daily complementary products audit found products that still need attention. This audit is read-only and does not sync anything back to Shopify.</p>

<table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">Product</th>
            <th align="left">Handle</th>
            <th align="left">Local Saved</th>
            <th align="left">Local Valid</th>
            <th align="left">Live Shopify Count</th>
            <th align="left">Live Shopify Valid</th>
            <th align="left">Issues</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($alerts as $alert)
            <tr>
                <td>{{ $alert['product']->title ?: ('Product #' . $alert['product']->id) }}</td>
                <td>{{ $alert['product']->handle }}</td>
                <td>{{ $alert['local_total'] }}</td>
                <td>{{ $alert['local_eligible'] }}</td>
                <td>{{ $alert['shopify_current'] ?? 0 }}</td>
                <td>{{ $alert['shopify_eligible'] }}</td>
                <td>
                    @php
                        $issues = [];
                        foreach (($alert['local_ineligible'] ?? []) as $item) {
                            $label = trim((string) ($item['title'] ?? '')) ?: (trim((string) ($item['handle'] ?? '')) ?: 'Unknown product');
                            $issues[] = 'Local ref invalid on Shopify: ' . $label . ' (' . (($item['reason'] ?? 'Unknown issue')) . ')';
                        }
                        foreach (($alert['shopify_ineligible'] ?? []) as $item) {
                            $label = trim((string) ($item['title'] ?? '')) ?: (trim((string) ($item['handle'] ?? '')) ?: 'Unknown product');
                            $issues[] = 'Shopify ref invalid: ' . $label . ' (' . (($item['reason'] ?? 'Unknown issue')) . ')';
                        }
                    @endphp
                    {{ $issues !== [] ? implode('; ', $issues) : 'None' }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
