<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #0f766e; color: #fff; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .content { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 24px; }
        .card { background-color: #fff; border: 1px solid #d1fae5; border-left: 5px solid #0f766e; padding: 16px; margin: 16px 0; }
        .button { display: inline-block; background-color: #0f766e; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Purchase Order Ready for Signature</h2>
    </div>
    <div class="content">
        <p>A purchase order has been generated and is waiting for the Supply Chain Director to sign it.</p>
        <div class="card">
            <p><strong>MRF ID:</strong> {{ $mrf->formatted_id ?? $mrf->mrf_id }}</p>
            <p><strong>PO Number:</strong> {{ $mrf->po_number ?? 'Pending assignment' }}</p>
            <p><strong>Title:</strong> {{ $mrf->title }}</p>
            <p><strong>Requester:</strong> {{ $mrf->requester_name }}</p>
            <p><strong>Vendor:</strong> {{ $mrf->selectedVendor->name ?? 'Not specified' }}</p>
        </div>
        <p>
            <a class="button" href="{{ $signingUrl }}">Review and sign PO</a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated workflow notification from {{ config('app.name', 'Emerald Industrial CFZE') }}.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name', 'Emerald Industrial CFZE') }}. All rights reserved.</p>
    </div>
</body>
</html>
