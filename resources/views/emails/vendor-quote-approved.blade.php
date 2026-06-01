<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #059669; color: #fff; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .content { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 24px; }
        .card { background-color: #fff; border: 1px solid #d1fae5; border-left: 5px solid #059669; padding: 16px; margin: 16px 0; }
        .button { display: inline-block; background-color: #059669; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Quotation Approved</h2>
    </div>
    <div class="content">
        <p>Your quotation has been approved for the procurement request below.</p>
        <div class="card">
            <p><strong>MRF:</strong> {{ $mrf->formatted_id ?? $mrf->mrf_id }}</p>
            <p><strong>Quotation ID:</strong> {{ $quotation->quotation_id }}</p>
            <p><strong>Amount:</strong> {{ $quotation->currency ?? 'NGN' }} {{ number_format((float) $quotation->total_amount, 2) }}</p>
        </div>

        @if($invoiceGateOpen)
            <p>You may now submit your final invoice and supporting documents through the vendor portal.</p>
        @elseif(($gateType ?? null) === 'delivery')
            <p>Please submit your final invoice after delivery has been confirmed and the goods received note (GRN) is completed. We will notify you when the invoice submission window opens.</p>
        @else
            <p>We will notify you when the invoice submission window opens in the vendor portal.</p>
        @endif

        <p>
            <a class="button" href="{{ $invoiceUploadUrl ?? $vendorPortalUrl }}">Open Vendor Portal</a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated notification from Emerald Industrial CFZE.</p>
        <p>&copy; {{ date('Y') }} Emerald Industrial CFZE. All rights reserved.</p>
    </div>
</body>
</html>
