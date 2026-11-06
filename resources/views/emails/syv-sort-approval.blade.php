<p>{{ $details['submitter_name'] }} submitted Shop Your Vibe sorting for approval.</p>

<ul>
    <li>Collection: {{ $details['collection_name'] }}</li>
    <li>Submitter: {{ $details['submitter_name'] }}</li>
    <li>Submitted: {{ $details['submitted_at'] }}</li>
    <li>Manually moved products: {{ $details['moved_count'] }}</li>
    <li>Sorting status: {{ $details['status'] }}</li>
</ul>

@if (!empty($details['collections']))
    <p>Collections included:</p>
    <ul>
        @foreach ($details['collections'] as $collection)
            <li>{{ $collection }}</li>
        @endforeach
    </ul>
@endif

<p><a href="{{ $details['review_url'] }}">Open this revision in Shop Your Vibe</a></p>
