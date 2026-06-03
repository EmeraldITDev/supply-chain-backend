<p>Hello {{ $passengerName }},</p>

<p>Your trip request <strong>{{ $tripCode }}</strong> has been confirmed by logistics.</p>

<ul>
    <li><strong>Date / time:</strong> {{ $departure ?? 'To be advised' }}@if($arrival) — return {{ $arrival }}@endif</li>
    <li><strong>From:</strong> {{ $origin ?? 'Office' }}</li>
    <li><strong>Destination:</strong> {{ $destination }}</li>
    <li><strong>Purpose:</strong> {{ $purpose }}</li>
</ul>

<p>For questions, contact the requester:</p>
<ul>
    <li><strong>{{ $requesterName }}</strong></li>
    @if(!empty($requesterEmail))
        <li>Email: {{ $requesterEmail }}</li>
    @endif
    @if(!empty($requesterPhone))
        <li>Phone: {{ $requesterPhone }}</li>
    @endif
</ul>

<p>Thank you.</p>
