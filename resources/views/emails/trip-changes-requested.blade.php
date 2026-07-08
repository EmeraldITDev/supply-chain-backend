<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Trip Request — Changes Requested</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #333;">
    <h2>Changes Requested on Your Trip Request</h2>
    <p>Hello {{ $requesterName }},</p>
    <p><strong>{{ $reviewerName }}</strong> has requested changes to your trip request before it can proceed.</p>
    <ul>
        <li><strong>Reference:</strong> {{ $tripCode }}</li>
        <li><strong>Route:</strong> {{ $origin ?? '—' }} → {{ $destination ?? '—' }}</li>
        <li><strong>Reason:</strong> {{ $reason }}</li>
    </ul>
    <p>Please log in to the supply chain portal, open your trip request, make the requested updates, and resubmit.</p>
</body>
</html>
