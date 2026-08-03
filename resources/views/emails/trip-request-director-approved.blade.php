<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trip request approved</title>
</head>
<body>
    <p>Hello,</p>

    <p>
        Trip request <strong>{{ $tripCode }}</strong> was approved by Supply Chain Director
        <strong>{{ $approverName }}</strong> and is now awaiting logistics processing.
    </p>

    <p>
        <strong>Requester:</strong> {{ $requesterName ?? 'Unknown' }}<br>
        <strong>Origin:</strong> {{ $origin ?? '—' }}<br>
        <strong>Destination:</strong> {{ $destination ?? '—' }}<br>
        <strong>Purpose:</strong> {{ $purpose ?? '—' }}<br>
        <strong>Scheduled departure:</strong> {{ $departure ?? '—' }}
    </p>

    <p>
        Open the trip request here:
        <a href="{{ config('app.frontend_url', url('/')) }}{{ $deepLink }}">{{ config('app.frontend_url', url('/')) }}{{ $deepLink }}</a>
    </p>
</body>
</html>
