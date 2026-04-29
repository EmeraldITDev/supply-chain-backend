@component('mail::message')
# Action Required: Your Vendor Documents Have Expired

Hello {{ $vendorName }},

We wanted to inform you that one or more of your required vendor registration documents have expired and require renewal.

## Expired Documents

@component('mail::table')
| Document Type | Expiry Date | Status |
|:--------------|:------------|:-------|
@foreach($expiredDocuments as $document)
| {{ $document['document_type'] ?? 'N/A' }} | {{ \Carbon\Carbon::parse($document['expiry_date'])->format('M d, Y') }} | Expired |
@endforeach
@endcomponent

## What You Need to Do

Please upload renewed versions of the above documents as soon as possible to maintain your vendor registration status. Without recent documents, your registration may be marked as **Documents Incomplete** and could affect your ability to receive orders.

To upload your renewed documents:
1. Log in to your vendor dashboard at {{ config('app.frontend_url') }}
2. Navigate to "Vendor Profile" → "Documents"
3. Upload the renewed versions of the expired documents
4. Submit for approval

## Important Information

- **Deadline**: Please upload renewed documents within 7 days
- **Support**: If you need assistance, contact our vendor support team at {{ config('app.vendor_support_email', 'support@company.com') }}
- **Registration Status**: Your current registration status is: **{{ $registrationStatus }}**

---

**Note**: This is an automated message. Please do not reply to this email.

@component('mail::button', ['url' => config('app.frontend_url') . '/vendor-portal', 'color' => 'primary'])
Upload Documents Now
@endcomponent

Thank you for your cooperation and continued partnership.

Best regards,
{{ config('app.name', 'Supply Chain Team') }}

---

*Sent at {{ now()->format('M d, Y H:i:s') }}*
@endcomponent
