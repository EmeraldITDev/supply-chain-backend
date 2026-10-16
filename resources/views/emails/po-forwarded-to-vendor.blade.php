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
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Signed Purchase Order</h2>
    </div>
    <div class="content">
        <p>Hello{{ filled($mrf->selectedVendor?->name) ? ' '.$mrf->selectedVendor->name : '' }},</p>
        <p>
            Emerald Industrial CFZE has signed the purchase order below and is forwarding it to you.
            @if ($hasAttachment)
                The signed PDF is attached.
            @endif
        </p>
        <div class="card">
            <p><strong>PO Number:</strong> {{ $mrf->po_number ?? 'Pending assignment' }}</p>
            <p><strong>Title:</strong> {{ $mrf->title }}</p>
            <p><strong>Signed at:</strong> {{ $mrf->po_signed_at?->format('Y-m-d H:i') ?? 'Just now' }}</p>
        </div>
        <p>Procurement (procurement@emeraldcfze.com) is copied on this email.</p>
    </div>
    <div class="footer">
        <p>This message was sent by {{ config('app.name', 'Emerald Industrial CFZE') }} after Supply Chain approved forwarding the signed purchase order.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name', 'Emerald Industrial CFZE') }}. All rights reserved.</p>
    </div>
</body>
</html>
