<?php

namespace App\Notifications;

use App\Models\Logistics\VehicleMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VehicleMaintenanceOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private VehicleMaintenance $maintenance,
        private int $daysOverdue
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle = $this->maintenance->vehicle;
        
        $maintenanceTypeLabels = [
            'OIL_CHANGE' => 'Oil Change',
            'TIRE_ROTATION' => 'Tire Rotation',
            'BRAKE_INSPECTION' => 'Brake Inspection',
            'FILTER_REPLACEMENT' => 'Filter Replacement',
            'TRANSMISSION_SERVICE' => 'Transmission Service',
            'BATTERY_CHECK' => 'Battery Check',
            'COOLANT_FLUSH' => 'Coolant Flush',
            'GENERAL_INSPECTION' => 'General Inspection',
            'SAFETY_CHECK' => 'Safety Check',
        ];

        return (new MailMessage)
            ->subject("Urgent: Vehicle Maintenance Overdue - {$vehicle->plate_number}")
            ->greeting('Vehicle Maintenance Alert')
            ->line("OVERDUE MAINTENANCE")
            ->line("Vehicle: {$vehicle->plate_number} ({$vehicle->vehicle_code})")
            ->line("Maintenance Type: " . ($maintenanceTypeLabels[$this->maintenance->maintenance_type] ?? $this->maintenance->maintenance_type))
            ->line("Description: {$this->maintenance->description}")
            ->line("Was Due: {$this->maintenance->next_due_at?->format('Y-m-d')}")
            ->line("Days Overdue: {$this->daysOverdue}")
            ->when($this->maintenance->cost, fn($mail) => $mail->line("Estimated Cost: \${$this->maintenance->cost}"))
            ->action('Schedule Maintenance', url("/logistics/fleet/vehicles/{$vehicle->id}"))
            ->line('This vehicle maintenance is overdue. Please schedule and complete the maintenance immediately to ensure vehicle safety and compliance.')
            ->line('Failure to perform scheduled maintenance may affect warranty and vehicle insurance coverage.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicle = $this->maintenance->vehicle;

        return [
            'maintenance_id' => $this->maintenance->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_code' => $vehicle->vehicle_code,
            'plate_number' => $vehicle->plate_number,
            'maintenance_type' => $this->maintenance->maintenance_type,
            'description' => $this->maintenance->description,
            'next_due_at' => $this->maintenance->next_due_at,
            'days_overdue' => $this->daysOverdue,
            'cost' => $this->maintenance->cost,
            'severity' => $this->daysOverdue > 30 ? 'critical' : 'warning',
            'message' => "Maintenance {$this->maintenance->maintenance_type} for vehicle {$vehicle->plate_number} is overdue by {$this->daysOverdue} days",
            'action_url' => "/logistics/fleet/vehicles/{$vehicle->id}",
            'notification_type' => 'maintenance_overdue',
        ];
    }
}
