<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\SendNotificationRequest;
use App\Models\Logistics\NotificationEvent;
use App\Services\Logistics\NotificationService;

class NotificationController extends ApiController
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function send(SendNotificationRequest $request)
    {
        $recipients = $this->notificationService->resolveRecipientsByRoles($request->roles);

        $event = $this->notificationService->recordAndDispatch(
            $request->event_key,
            $request->type,
            $request->payload,
            $recipients
        );

        return $this->success([
            'event' => $event,
        ], 201);
    }

    public function index()
    {
        return $this->success([
            'events' => NotificationEvent::orderByDesc('created_at')->paginate(20),
        ]);
    }
}
