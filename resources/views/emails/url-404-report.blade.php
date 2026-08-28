<p>The latest completed site audit found {{ count($results) }} URL {{ \Illuminate\Support\Str::plural('404', count($results)) }} that need attention.</p>

<p>Only HTTP 404 responses are included in this monthly report.</p>

<table cellpadding="8" cellspacing="0" border="1" style="border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">URL</th>
            <th align="left">Final URL</th>
            <th align="left">Shopify status</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($results as $result)
            <tr>
                <td><a href="{{ $result['url'] }}">{{ $result['url'] }}</a></td>
                <td>{{ $result['final_url'] ?: '—' }}</td>
                <td>{{ $result['shopify_resource_status'] ?: 'Unknown' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
