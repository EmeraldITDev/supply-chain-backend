<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #7c3aed; color: #fff; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .content { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 24px; }
        .card { background-color: #fff; border: 1px solid #ede9fe; border-left: 5px solid #7c3aed; padding: 16px; margin: 16px 0; }
        .button { display: inline-block; background-color: #7c3aed; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Vendor Selection Update</h2>
    </div>
    <div class="content">
        <p>A vendor has been selected for an RFQ in the procurement workflow.</p>
        <div class="card">
            <p><strong>Quotation ID:</strong> {{ $quotation->quotation_id }}</p>
            <p><strong>RFQ ID:</strong> {{ optional($quotation->rfq)->rfq_id }}</p>
            <p><strong>Selected Vendor:</strong> {{ $quotation->vendor_name ?? optional($quotation->vendor)->name }}</p>
            <p><strong>Award Amount:</strong> {{ $quotation->currency ?? 'NGN' }} {{ number_format((float) $quotation->total_amount, 2) }}</p>
            <p><strong>Status:</strong> {{ $quotation->status }}</p>
        </div>
        <p>
            <a class="button" href="{{ rtrim((string) config('app.frontend_url'), '/') . '/rfqs/' . optional($quotation->rfq)->rfq_id }}">
                View RFQ Details
            </a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated workflow notification.</p>
    </div>
</body>
</html>
