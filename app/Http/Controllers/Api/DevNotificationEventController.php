<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use Illuminate\Http\Request;

class DevNotificationEventController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => NotificationEvent::query()
                ->orderByDesc('id')
                ->get()
                ->map(fn (NotificationEvent $event) => $this->serializeEvent($event))
                ->values(),
        ]);
    }

    public function destroy(Request $request)
    {
        $this->authorizeAdmin($request);

        NotificationEvent::query()->delete();

        return response()->json([
            'message' => 'Notification events cleared successfully.',
        ]);
    }

    private function serializeEvent(NotificationEvent $event): array
    {
        $payload = $event->payload ?? [];
        $notificationType = str_contains((string) $event->event_type, 'invitation')
            ? 'invitation_notification'
            : 'completion_notification';

        return [
            'id' => (string) $event->id,
            'eventType' => $event->event_type,
            'type' => $notificationType,
            'action' => $event->action,
            'channel' => $event->channel,
            'recipientName' => $event->recipient_name,
            'recipientEmail' => $event->recipient_email,
            'documentTitle' => data_get($payload, 'document_title'),
            'invitationType' => data_get($payload, 'invitation_type'),
            'accessUrl' => data_get($payload, 'action_url'),
            'token' => data_get($payload, 'token'),
            'html' => data_get($payload, 'html'),
            'body' => data_get($payload, 'summary') ?? data_get($payload, 'intro_paragraph'),
            'subject' => $event->subject,
            'status' => $event->status,
            'createdAt' => optional($event->created_at)->toISOString(),
            'sentAt' => optional($event->sent_at)->toISOString(),
            'payload' => $payload,
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'You are not allowed to view notification events.');
    }
}
