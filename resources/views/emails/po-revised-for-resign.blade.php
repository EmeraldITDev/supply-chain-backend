<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 640px; margin: 0 auto; padding: 20px; }
        .header { background-color: #b45309; color: #fff; padding: 20px; text-align: center; border-radius: 6px 6px 0 0; }
        .content { border: 1px solid #e5e7eb; background-color: #fffbeb; padding: 24px; }
        .card { background-color: #fff; border: 1px solid #fde68a; border-left: 5px solid #b45309; padding: 16px; margin: 16px 0; }
        .changes { width: 100%; border-collapse: collapse; font-size: 13px; }
        .changes th, .changes td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        .changes th { background: #fef3c7; }
        .before { color: #9a3412; }
        .after { color: #166534; }
        .button { display: inline-block; background-color: #b45309; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 4px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Purchase Order revised — new signature required</h2>
    </div>
    <div class="content">
        <p>A previously signed Purchase Order has been revised and requires a new Supply Chain Director signature.</p>
        <div class="card">
            <p><strong>PO number:</strong> {{ $po_number }}</p>
            <p><strong>Revised by:</strong> {{ $editor_name }}</p>
            <p><strong>Revision time:</strong> {{ $revised_at }}</p>
            @if (!empty($unlock_reason))
                <p><strong>Unlock reason:</strong> {{ $unlock_reason }}</p>
            @endif
        </div>

        <h3>What changed</h3>
        @if (!empty($change_summary) && count($change_summary) > 0)
            <table class="changes">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Previous</th>
                        <th>New</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($change_summary as $change)
                        <tr>
                            <td>{{ $change['label'] ?? $change['field'] }}</td>
                            <td class="before">{{ $change['before_display'] ?? $change['before'] }}</td>
                            <td class="after">{{ $change['after_display'] ?? $change['after'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No field-level differences were recorded for this revision.</p>
        @endif

        <p style="margin-top: 20px;">
            <a class="button" href="{{ $signing_url }}">Open PO signing page</a>
        </p>
    </div>
    <div class="footer">
        <p>This is an automated workflow notification from {{ config('app.name', 'Emerald Industrial CFZE') }}.</p>
    </div>
</body>
</html>
