<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SystemAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $title;
    protected string $message;
    protected ?string $actionUrl;
    protected string $priority;
    protected array $metadata;

    public function __construct(
        string $title,
        string $message,
        $actionUrl = null,
        $priority = 'normal',
        array $metadata = []
    ) {
        $this->title = $title;
        $this->message = $message;
        
        // Handle case where array was passed as 3rd parameter (backward compatibility)
        if (is_array($actionUrl)) {
            $this->metadata = $actionUrl;
            $this->actionUrl = $actionUrl['action_url'] ?? null;
            // Priority might be in metadata array or as 4th parameter
            if (is_string($priority) && $priority !== 'normal') {
                $this->priority = $priority;
            } else {
                $this->priority = $actionUrl['priority'] ?? (is_string($priority) ? $priority : 'normal');
            }
        } else {
            // Standard usage: actionUrl is string, priority might be array or string
        $this->actionUrl = $actionUrl;
            if (is_array($priority)) {
                $this->metadata = $priority;
                $this->priority = $priority['priority'] ?? 'normal';
            } else {
        $this->priority = $priority;
                $this->metadata = $metadata;
            }
        }
        
        // Extract action_url from metadata if provided and actionUrl is null
        if (empty($this->actionUrl) && isset($this->metadata['action_url'])) {
            $this->actionUrl = $this->metadata['action_url'];
        }
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $data = [
            'type' => 'system_announcement',
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            'icon' => 'megaphone',
            'color' => 'indigo',
            'priority' => $this->priority,
        ];
        
        // Merge any additional metadata
        if (!empty($this->metadata)) {
            $data = array_merge($data, $this->metadata);
        }
        
        return $data;
    }
}
