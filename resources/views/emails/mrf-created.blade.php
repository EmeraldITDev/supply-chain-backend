<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New MRF Submitted</title>
</head>
<body>
    <h2>New MRF Submitted</h2>

    <p>A new Material Request Form has been submitted.</p>

    <p><strong>MRF ID:</strong> {{ $mrf->mrf_id }}</p>
    <p><strong>Title:</strong> {{ $mrf->title }}</p>
    <p><strong>Category:</strong> {{ $mrf->category }}</p>
    <p><strong>Contract Type:</strong> {{ $mrf->contract_type }}</p>
    <p><strong>Department:</strong> {{ $mrf->department ?? 'N/A' }}</p>
    <p><strong>Submitted By:</strong> {{ $mrf->requester_name }}</p>
    <p><strong>Status:</strong> {{ ucfirst($mrf->status) }}</p>

    <p>
        <a href="{{ config('app.frontend_url') . '/procurement/'">
            View MRF
        </a>
    </p>
</body>
</html>