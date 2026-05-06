<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #1d4ed8; color: #fff; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .content { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 24px; }
        .card { background-color: #fff; border: 1px solid #dbeafe; border-left: 5px solid #1d4ed8; padding: 16px; margin: 16px 0; }
        .button { display: inline-block; background-color: #1d4ed8; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" alt="Emerald Industrial CFZE" style="height: 60px; margin-bottom: 10px;">
        <h2>New Quotation Submitted</h2>
    </div>
    <div class="content">
        <p>A vendor has submitted a quotation that requires procurement review.</p>
        <div class="card">
            <p><strong>Quotation ID:</strong> {{ $quotation->quotation_id }}</p>
            <p><strong>RFQ ID:</strong> {{ optional($quotation->rfq)->rfq_id }}</p>
            <p><strong>Vendor:</strong> {{ $quotation->vendor_name ?? optional($quotation->vendor)->name }}</p>
            <p><strong>Total Amount:</strong> {{ $quotation->currency ?? 'NGN' }} {{ number_format((float) $quotation->total_amount, 2) }}</p>
            <p><strong>Status:</strong> {{ $quotation->status }}</p>
            <p><strong>Submitted At:</strong> {{ optional($quotation->submitted_at)->format('Y-m-d H:i') }}</p>
        </div>

        @php($attachmentsList = $attachments ?? ($quotation->attachments ?? []))
        @if($attachmentsList && count($attachmentsList) > 0)
        <div class="card" style="border-left-color: #059669;">
            <strong style="color: #059669;">📎 Supporting Documents</strong>
            <ul style="margin: 12px 0; padding-left: 20px;">
                @foreach($attachmentsList as $attachment)
                <li style="margin: 8px 0;">
                    @if(is_array($attachment))
                        @php
                            $url = $attachment['url'] ?? $attachment['file_share_url'] ?? $attachment['file_url'] ?? null;
                            $name = $attachment['name'] ?? $attachment['file_name'] ?? 'Document';
                        @endphp
                        @if($url)
                            <a href="{{ $url }}" style="color: #1d4ed8; text-decoration: none;">{{ $name }}</a>
                        @else
                            {{ $name }}
                        @endif
                    @else
                        <a href="{{ $attachment }}" style="color: #1d4ed8; text-decoration: none;">Supporting Document</a>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        <p>
            <a class="button" href="{{ $vendorPortalUrl ?? (rtrim((string) config('app.frontend_url'), '/') . '/vendor-portal') }}">
                Review Quotation
            </a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated workflow notification from Emerald Industrial CFZE.</p>
        <p>&copy; {{ date('Y') }} Emerald Industrial CFZE. All rights reserved.</p>
    </div>
</body>
</html>
