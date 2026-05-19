<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use Illuminate\Http\Request;

class DevNotificationEventController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

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
        abort_unless(app()->environment(['local', 'testing']), 404);

        NotificationEvent::query()->delete();

        return response()->json([
            'message' => 'Notification events cleared successfully.',
        ]);
    }

    private function serializeEvent(NotificationEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'eventType' => $event->event_type,
            'action' => $event->action,
            'channel' => $event->channel,
            'recipientName' => $event->recipient_name,
            'recipientEmail' => $event->recipient_email,
            'subject' => $event->subject,
            'status' => $event->status,
            'sentAt' => optional($event->sent_at)->toISOString(),
            'payload' => $event->payload,
        ];
    }
}

