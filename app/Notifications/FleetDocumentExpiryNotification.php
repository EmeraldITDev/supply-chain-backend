<?php

namespace App\Notifications;

use App\Models\Logistics\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetDocumentExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Document $document,
        private int $daysUntilExpiry
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle = $this->document->documentable;
        
        $urgency = match(true) {
            $this->daysUntilExpiry < 0 => 'EXPIRED',
            $this->daysUntilExpiry <= 7 => 'URGENT: Expiring within 7 days',
            $this->daysUntilExpiry <= 14 => 'WARNING: Expiring within 14 days',
            default => 'NOTICE: Expiring within 30 days',
        };

        return (new MailMessage)
            ->subject("Fleet Document Alert: {$this->document->document_type} - {$vehicle->plate_number}")
            ->greeting('Fleet Document Expiry Alert')
            ->line("Vehicle: {$vehicle->plate_number}")
            ->line("Document Type: {$this->document->document_type}")
            ->line("File: {$this->document->file_name}")
            ->line("Expires: {$this->document->expires_at?->format('Y-m-d')}")
            ->line("Status: {$urgency}")
            ->when($this->daysUntilExpiry >= 0, fn($mail) => $mail->line("Days Remaining: {$this->daysUntilExpiry}"))
            ->when($this->daysUntilExpiry < 0, fn($mail) => $mail->line("This document is OVERDUE for renewal by " . abs($this->daysUntilExpiry) . " days"))
            ->action('View Fleet Details', url("/logistics/fleet/vehicles/{$vehicle->id}"))
            ->line('Please take necessary action to renew this document and maintain fleet compliance.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicle = $this->document->documentable;

        return [
            'document_id' => $this->document->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_code' => $vehicle->vehicle_code,
            'plate_number' => $vehicle->plate_number,
            'document_type' => $this->document->document_type,
            'file_name' => $this->document->file_name,
            'expires_at' => $this->document->expires_at,
            'days_until_expiry' => $this->daysUntilExpiry,
            'severity' => match(true) {
                $this->daysUntilExpiry < 0 => 'critical',
                $this->daysUntilExpiry <= 7 => 'urgent',
                $this->daysUntilExpiry <= 14 => 'warning',
                default => 'info',
            },
            'message' => "Fleet document {$this->document->document_type} for vehicle {$vehicle->plate_number} expires on {$this->document->expires_at?->format('Y-m-d')}",
            'action_url' => "/logistics/fleet/vehicles/{$vehicle->id}",
            'notification_type' => 'fleet_document_expiry',
        ];
    }
}
