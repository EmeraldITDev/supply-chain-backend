<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>MRF Rejected</title>
</head>
<body>
    <h2>MRF Rejected</h2>

    <p>Your Material Request Form has been rejected.</p>

    <p><strong>MRF ID:</strong> {{ $mrf->mrf_id }}</p>
    <p><strong>Title:</strong> {{ $mrf->title }}</p>
    <p><strong>Status:</strong> {{ ucfirst($mrf->status) }}</p>

    @if($remarks)
        <p><strong>Remarks:</strong> {{ $remarks }}</p>
    @endif

    <p>
    <a href="{{ config('app.frontend_url') . '/procurement/'">
        View MRF
        </a>
    </p>
</body>
</html>