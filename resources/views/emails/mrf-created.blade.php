<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New MRF Created</title>
</head>
<body>
    <h2>New MRF Created</h2>

    <p>A new MRF has been created in the system.</p>

    <p><strong>MRF ID:</strong> {{ $data['mrf_id'] ?? 'N/A' }}</p>
    <p><strong>Title:</strong> {{ $data['title'] ?? 'N/A' }}</p>
    <p><strong>Department:</strong> {{ $data['department'] ?? 'N/A' }}</p>
    <p><strong>Created By:</strong> {{ $data['created_by'] ?? 'N/A' }}</p>
    <p><strong>Status:</strong> {{ $data['status'] ?? 'N/A' }}</p>

    @if(!empty($data['url']))
        <p><a href="{{ $data['url'] }}">View Request</a></p>
    @endif
</body>
</html>