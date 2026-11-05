<p>Hello {{ $requesterName }},</p>

<p>Your trip request <strong>{{ $tripCode }}</strong> from {{ $origin }} to {{ $destination }} was rejected.</p>

@if ($reviewerName)
    <p>Reviewed by: {{ $reviewerName }}</p>
@endif

<p>Reason: {{ $reason ?: 'No additional reason was provided.' }}</p>
