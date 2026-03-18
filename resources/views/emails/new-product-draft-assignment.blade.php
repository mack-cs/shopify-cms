<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $assignment->subject }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #1f2937;">
    <p>A new product draft task assignment has been sent to you.</p>

    @if (!empty($assignment->body))
        <p>{!! nl2br(e($assignment->body)) !!}</p>
    @endif

    <p>
        Draft count: {{ $assignment->items->count() }}<br>
        Work columns: {{ empty($columnLabels) ? 'None selected' : implode(', ', $columnLabels) }}<br>
        Identifier: Handle
    </p>

    <p>The attached CSV contains the selected drafts and the requested columns.</p>

    <p style="margin-top: 24px; color: #4b5563;">
        Sent by {{ $assignment->from_name ?: 'Shopify Editor' }}
        @if (!empty($assignment->from_email))
            &lt;{{ $assignment->from_email }}&gt;
        @endif
    </p>
</body>
</html>
