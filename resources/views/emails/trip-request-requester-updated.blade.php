<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Trip Request Updated</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #333;">
    <h2>Trip Request Updated</h2>
    <p><strong>{{ $editorName }}</strong> updated trip request <strong>{{ $tripCode }}</strong>.</p>
    <p><strong>Changes:</strong> {{ $changeSummary }}</p>
    <ul>
        <li><strong>Route:</strong> {{ $origin ?? '—' }} → {{ $destination ?? '—' }}</li>
    </ul>
    <p>Please log in to the supply chain portal and review the updated request under Logistics → Requests.</p>
</body>
</html>
