<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New SRF Submitted</title>
</head>
<body>
    <h2>New SRF Submitted</h2>

    <p>A new Store Requisition Form has been submitted.</p>

    <p><strong>SRF ID:</strong> {{ $srf->srf_id }}</p>
    <p><strong>Title:</strong> {{ $srf->title ?? 'N/A' }}</p>
    <p><strong>Department:</strong> {{ $srf->department ?? 'N/A' }}</p>
    <p><strong>Submitted By:</strong> {{ $srf->requester_name ?? 'N/A' }}</p>
    <p><strong>Status:</strong> {{ ucfirst($srf->status ?? 'pending') }}</p>

    <p>
    <a href="{{ config('app.frontend_url') . '/procurement/'">
        View SRF
        </a>
    </p>
</body>
</html>