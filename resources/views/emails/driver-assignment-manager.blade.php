<p>A driver has been assigned in fleet management.</p>
<p><strong>Driver:</strong> {{ $driver->name }}</p>
<p><strong>Phone:</strong> {{ $driver->phone_number }}</p>
@if(!empty($vehicleLabel))
<p><strong>Vehicle:</strong> {{ $vehicleLabel }}</p>
@endif
