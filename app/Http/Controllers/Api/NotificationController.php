<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $this->resolveListLimit($request);
            $unreadOnly = $request->boolean('unread_only', false);

            $query = $user->notifications()->orderBy('created_at', 'desc');

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            $notifications = $query->paginate($perPage);

            $formattedNotifications = collect($notifications->items())
                ->map(fn (DatabaseNotification $notification) => $this->formatNotification($notification))
                ->values()
                ->all();

            return response()->json([
                'success' => true,
                'data' => $formattedNotifications,
                'notifications' => $formattedNotifications,
                'pagination' => [
                    'total' => $notifications->total(),
                    'per_page' => $notifications->perPage(),
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'from' => $notifications->firstItem(),
                    'to' => $notifications->lastItem(),
                ],
                'unread_count' => $user->unreadNotifications()->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch notifications', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $unreadCount = $user->unreadNotifications()->count();

            return response()->json([
                'success' => true,
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single notification
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $notification = $user->notifications()->find($id);

            if (! $notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $formattedNotification = $this->formatNotification($notification);

            return response()->json([
                'success' => true,
                'data' => $formattedNotification,
                'notification' => $formattedNotification,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $notification = $user->notifications()->find($id);

            if (! $notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $notification->markAsRead();

            $formattedNotification = $this->formatNotification($notification);
            $formattedNotification['is_read'] = true;

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $formattedNotification,
                'notification' => $formattedNotification,
                'unread_count' => $user->unreadNotifications()->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->unreadNotifications->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $notification = $user->notifications()->find($id);

            if (! $notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], 404);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete all notifications
     */
    public function destroyAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->notifications()->delete();

            return response()->json([
                'success' => true,
                'message' => 'All notifications deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete all notifications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a system announcement (Admin only)
     */
    public function sendAnnouncement(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! in_array($user->scmRole(), ['admin', 'chairman', 'executive'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only administrators can send announcements.',
                ], 403);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'message' => 'required|string',
                'roles' => 'nullable|array',
                'roles.*' => 'string',
                'action_url' => 'nullable|string|max:255',
                'priority' => 'nullable|in:low,normal,high',
            ]);

            $this->notificationService->sendSystemAnnouncement(
                $validated['title'],
                $validated['message'],
                $validated['roles'] ?? null,
                $validated['action_url'] ?? null,
                $validated['priority'] ?? 'normal'
            );

            return response()->json([
                'success' => true,
                'message' => 'System announcement sent successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send announcement',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get notification statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $stats = [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                'read' => $user->notifications()->whereNotNull('read_at')->count(),
                'by_type' => [],
            ];

            $typeGroups = $user->notifications()
                ->get()
                ->groupBy(function ($notification) {
                    $data = $notification->data;

                    return $data['type'] ?? $data['notification_type'] ?? 'unknown';
                })
                ->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'unread' => $group->whereNull('read_at')->count(),
                    ];
                });

            $stats['by_type'] = $typeGroups;

            return response()->json([
                'success' => true,
                'statistics' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get notification statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNotification(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $type = $data['type'] ?? $data['notification_type'] ?? 'unknown';
        $message = $data['message'] ?? ($data['payload']['message'] ?? '');
        $title = $data['title'] ?? null;

        if (! filled($title) && filled($message)) {
            $title = Str::limit($message, 100);
        }

        if (! filled($title)) {
            $title = $this->titleFromType((string) $type);
        }

        return [
            'id' => $notification->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $data['action_url'] ?? ($data['payload']['action_url'] ?? null),
            'icon' => $data['icon'] ?? 'bell',
            'color' => $data['color'] ?? 'blue',
            'priority' => $data['priority'] ?? 'normal',
            'read_at' => $notification->read_at ? $notification->read_at->toIso8601String() : null,
            'created_at' => $notification->created_at->toIso8601String(),
            'is_read' => $notification->read_at !== null,
            'data' => $data,
        ];
    }

    private function titleFromType(string $type): string
    {
        return match ($type) {
            'mrf_submitted' => 'New MRF Submitted',
            'mrf_approved' => 'MRF Approved',
            'mrf_rejected' => 'MRF Rejected',
            'system_announcement' => 'System Announcement',
            'rfq_assigned' => 'New RFQ Assignment',
            'quotation_submitted' => 'New Quotation Received',
            'quotation_status_updated' => 'Quotation Status Updated',
            'vendor_registration' => 'New Vendor Registration',
            'vendor_approved' => 'Vendor Registration Approved',
            'vendor_quote_approved' => 'Quotation Approved',
            'journey_status_update' => 'Journey Status Update',
            default => Str::title(str_replace('_', ' ', $type)),
        };
    }

    private function resolveListLimit(Request $request): int
    {
        $limit = $request->input('limit', $request->input('per_page', 50));

        return min(100, max(1, (int) $limit));
    }
}
