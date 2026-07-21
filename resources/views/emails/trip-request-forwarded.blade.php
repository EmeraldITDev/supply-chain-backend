<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Trip Request Forwarded</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #333;">
    <h2>Trip Request Forwarded for Review</h2>
    <p><strong>{{ $forwardedByName }}</strong> has forwarded a trip request to you for approval.</p>
    <ul>
        <li><strong>Reference:</strong> {{ $tripCode }}</li>
        <li><strong>Route:</strong> {{ $origin ?? '—' }} → {{ $destination ?? '—' }}</li>
        @if($departure)
            <li><strong>Departure:</strong> {{ $departure }}</li>
        @endif
        @if($purpose)
            <li><strong>Purpose:</strong> {{ $purpose }}</li>
        @endif
    </ul>
    <p>Please log in to the supply chain portal and review pending requests under Logistics → Requests.</p>
</body>
</html>
