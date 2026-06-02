<?php

namespace App\Services;

use App\Mail\DocumentCompletionMail;
use App\Models\Document;
use App\Models\NotificationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DocumentCompletionNotifier
{
    public function notify(Request $request, Document $document, string $completionType, string $actorName, ?int $invitationId = null): void
    {
        $document->loadMissing(['owner', 'createdBy']);
        $recipient = $document->owner ?: $document->createdBy;

        if (! $recipient || ! filled($recipient->email)) {
            return;
        }

        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $subjectLabel = str_starts_with($completionType, 'sign') ? 'Signing Completed' : 'Review Completed';

        $mailData = [
            'subject_line' => sprintf('%s: %s', $subjectLabel, $document->title),
            'portal_name' => config('app.name'),
            'recipient_name' => $recipient->username,
            'summary' => str_starts_with($completionType, 'sign')
                ? sprintf('%s completed signing for "%s".', $actorName, $document->title)
                : sprintf('%s completed a review for "%s".', $actorName, $document->title),
            'action_label' => 'Open Documents',
            'action_url' => $frontendUrl . '/documents',
            'support_email' => config('mail.from.address'),
            'details' => [
                ['label' => 'Document title', 'value' => $document->title],
                ['label' => 'Document ID', 'value' => $document->document_uuid],
                ['label' => 'Completed by', 'value' => $actorName],
                ['label' => 'Completed at', 'value' => now()->toDayDateTimeString()],
                ['label' => 'Current status', 'value' => $document->status],
            ],
            'footer_note' => 'This notification was generated automatically from the DRS workflow.',
        ];

        Mail::to($recipient->email)->queue(
            (new DocumentCompletionMail($mailData))->afterCommit()
        );

        NotificationEvent::query()->create([
            'event_type' => $completionType,
            'action' => $completionType,
            'channel' => 'mail',
            'recipient_name' => $recipient->username,
            'recipient_email' => $recipient->email,
            'user_id' => $recipient->id,
            'document_id' => $document->id,
            'invitation_id' => $invitationId,
            'subject' => $mailData['subject_line'],
            'payload' => $mailData,
            'status' => 'queued',
        ]);
    }
}
