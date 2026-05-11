<?php

namespace App\Notifications;

use App\Models\Logistics\VehicleMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VehicleMaintenanceUpcomingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private VehicleMaintenance $maintenance
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

        return (new MailMessage)
            ->subject("Upcoming maintenance: {$vehicle->plate_number}")
            ->greeting('Fleet maintenance reminder')
            ->line("Vehicle: {$vehicle->plate_number} ({$vehicle->vehicle_code})")
            ->line("Maintenance type: {$this->maintenance->maintenance_type}")
            ->line('Due on: ' . ($this->maintenance->next_due_at?->format('Y-m-d') ?? 'N/A'))
            ->action('View vehicle', url("/logistics/fleet/vehicles/{$vehicle->id}"));
    }

    public function toArray(object $notifiable): array
    {
        $vehicle = $this->maintenance->vehicle;

        return [
            'maintenance_id' => $this->maintenance->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_plate' => $vehicle->plate_number,
            'maintenance_type' => $this->maintenance->maintenance_type,
            'next_maintenance_date' => $this->maintenance->next_due_at?->format('Y-m-d'),
            'recipient_user_id' => $notifiable->getAuthIdentifier(),
            'notification_type' => 'fleet_maintenance_upcoming',
        ];
    }
}
