<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approval request from {{ $requester->name ?: 'Requester' }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #1f2937;">
    <p>{{ $requester->name ?: 'A requester' }} has requested approval in Shopify Editor.</p>

    <p>
        Routing:
        @if ($targetApprover)
            Assigned specifically to {{ $targetApprover->name }}.
        @else
            Open to all eligible reviewers.
        @endif
        <br>
        Requested fields: {{ empty($requestedFieldLabels) ? 'Not specified' : implode(', ', $requestedFieldLabels) }}<br>
        Product count: {{ count($requests) }}
    </p>

    @php
        $firstNote = '';
        foreach ($requests as $request) {
            $note = trim((string) ($request->request_note ?? ''));
            if ($note !== '') {
                $firstNote = $note;
                break;
            }
        }
    @endphp

    @if ($firstNote !== '')
        <p><strong>Requester note:</strong><br>{!! nl2br(e($firstNote)) !!}</p>
    @endif

    <p><strong>Products:</strong></p>
    <ul>
        @foreach ($requests as $request)
            <li>
                {{ trim((string) ($request->product?->title ?? '')) ?: ('Product #' . $request->product_id) }}
                @php $handle = trim((string) ($request->product?->handle ?? '')); @endphp
                @if ($handle !== '')
                    ({{ $handle }})
                @endif
            </li>
        @endforeach
    </ul>

    @if ($queueUrl !== '')
        <p>
            Review queue:
            <a href="{{ $queueUrl }}">{{ $queueUrl }}</a>
        </p>
    @endif

    <p style="margin-top: 24px; color: #4b5563;">
        Sent on behalf of {{ $requester->name ?: 'Requester' }}
        @if (!empty($requester->email))
            &lt;{{ $requester->email }}&gt;
        @endif
    </p>
</body>
</html>
