<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>MRF Rejected</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #1e5f63; color: white; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .header img { height: 60px; margin-bottom: 10px; }
        .content { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 24px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" alt="Emerald Industrial CFZE">
        <h2 style="margin: 10px 0; color: white;">MRF Rejected</h2>
    </div>
    <div class="content">
        <h2 style="margin-top: 0;">MRF Rejected</h2>

        <p>Your Material Request Form has been rejected.</p>

        <p><strong>MRF ID:</strong> {{ $mrf->mrf_id }}</p>
        <p><strong>Title:</strong> {{ $mrf->title }}</p>
        <p><strong>Status:</strong> {{ ucfirst($mrf->status) }}</p>

        @if($remarks)
            <p><strong>Remarks:</strong> {{ $remarks }}</p>
        @endif

        <p>
            <a href="{{ config('app.frontend_url') . '/procurement/' }}">
                View MRF
            </a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated notification from {{ config('app.name', 'Emerald Industrial CFZE') }}.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name', 'Emerald Industrial CFZE') }}. All rights reserved.</p>
    </div>
</body>
</html>
