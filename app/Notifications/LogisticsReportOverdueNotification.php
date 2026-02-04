<?php

namespace App\Notifications;

use App\Models\Logistics\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LogisticsReportOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Report $report,
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
        $reportTypeLabels = [
            'INCIDENT' => 'Incident Report',
            'DELIVERY_CONFIRMATION' => 'Delivery Confirmation',
            'VEHICLE_INSPECTION' => 'Vehicle Inspection Report',
            'COMPLIANCE_CHECK' => 'Compliance Check',
        ];

        return (new MailMessage)
            ->subject("Overdue Report: {$reportTypeLabels[$this->report->report_type] ?? $this->report->report_type}")
            ->greeting('Logistics Report Overdue')
            ->line("A logistics report is overdue and requires immediate attention.")
            ->line("Report Type: " . ($reportTypeLabels[$this->report->report_type] ?? $this->report->report_type))
            ->line("Report ID: {$this->report->id}")
            ->when($this->report->trip_id, fn($mail) => $mail->line("Trip Code: {$this->report->trip?->trip_code}"))
            ->line("Due Date: {$this->report->due_at?->format('Y-m-d')}")
            ->line("Days Overdue: {$this->daysOverdue}")
            ->when($this->report->description, fn($mail) => $mail->line("Description: {$this->report->description}"))
            ->action('Submit Report', url("/logistics/reports/{$this->report->id}"))
            ->line('Please complete and submit this report as soon as possible to maintain compliance and operational continuity.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'report_id' => $this->report->id,
            'report_type' => $this->report->report_type,
            'trip_id' => $this->report->trip_id,
            'trip_code' => $this->report->trip?->trip_code,
            'due_at' => $this->report->due_at,
            'days_overdue' => $this->daysOverdue,
            'status' => $this->report->status,
            'description' => $this->report->description,
            'severity' => $this->daysOverdue > 14 ? 'critical' : 'warning',
            'message' => "{$this->report->report_type} report is overdue by {$this->daysOverdue} days",
            'action_url' => "/logistics/reports/{$this->report->id}",
            'notification_type' => 'report_overdue',
        ];
    }
}
