<p>Hello {{ $driver->name }},</p>
<p>You have been assigned as a driver on the Emerald SCM logistics platform.</p>
@if(!empty($vehicleLabel))
<p><strong>Vehicle:</strong> {{ $vehicleLabel }}</p>
@endif
<p>Contact your logistics manager if you have questions.</p>
