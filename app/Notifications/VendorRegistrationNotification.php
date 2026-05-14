<?php

namespace App\Notifications;

use App\Models\VendorRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorRegistrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected VendorRegistration $registration;

    public function __construct(VendorRegistration $registration)
    {
        $this->registration = $registration;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Email alert for new vendor self-registration.
     */
    public function toMail($notifiable): MailMessage
    {
        $company = $this->registration->company_name;
        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/');
        $reviewUrl = $frontend . '/vendors/registrations/' . $this->registration->id;

        return (new MailMessage)
            ->subject('New vendor registration: ' . $company)
            ->greeting('Hello ' . ($notifiable->name ?? '') . ',')
            ->line('A new vendor has registered on the platform and is pending review.')
            ->line('**Company:** ' . $company)
            ->line('**Category:** ' . ($this->registration->category ?? 'N/A'))
            ->line('**Contact email:** ' . ($this->registration->email ?? 'N/A'))
            ->action('Open registration', $reviewUrl)
            ->line('You are receiving this because you are on the procurement or logistics notification list.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $company = $this->registration->company_name;

        return [
            'type' => 'vendor_registration',
            'title' => 'New Vendor Registration',
            'message' => "A new vendor registration has been submitted by {$company}",
            'registration_id' => $this->registration->id,
            'vendor_name' => $company,
            'email' => $this->registration->email,
            'category' => $this->registration->category,
            'action_url' => "/vendors/registrations/{$this->registration->id}",
            'icon' => 'user-add',
            'color' => 'blue',
            'priority' => 'normal',
        ];
    }
}
