<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentInvitation;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(
        string $action,
        User $performedBy,
        ?User $targetUser = null,
        ?Document $document = null,
        ?string $documentTitle = null,
        ?string $details = null,
        ?string $ipAddress = null,
        ?string $eventType = null,
        ?int $invitationId = null,
        ?array $metadata = null
    ): AuditLog {
        return AuditLog::query()->create([
            'timestamp' => now(),
            'event_type' => $eventType ?? $action,
            'action' => $action,
            'performed_by' => $performedBy->username,
            'performed_by_id' => $performedBy->id,
            'actor_id' => $performedBy->id,
            'actor_name' => $performedBy->username,
            'target_user' => $targetUser?->username,
            'target_user_id' => $targetUser?->id,
            'document_title' => $documentTitle ?? $document?->title,
            'document_id' => $document?->id,
            'invitation_id' => $invitationId,
            'details' => $details,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
        ]);
    }

    public function fromRequest(
        string $action,
        Request $request,
        ?User $targetUser = null,
        ?Document $document = null,
        ?string $documentTitle = null,
        ?string $details = null,
        ?int $invitationId = null,
        ?array $metadata = null
    ): AuditLog {
        $user = $request->user();

        if ($user) {
            return $this->record(
                action: $action,
                performedBy: $user,
                targetUser: $targetUser,
                document: $document,
                documentTitle: $documentTitle,
                details: $details,
                ipAddress: $request->ip(),
                invitationId: $invitationId,
                metadata: $metadata
            );
        }

        return $this->recordAnonymous(
            action: $action,
            targetUser: $targetUser,
            document: $document,
            documentTitle: $documentTitle,
            details: $details,
            ipAddress: $request->ip(),
            invitationId: $invitationId,
            metadata: $metadata
        );
    }

    public function recordAnonymous(
        string $action,
        ?User $targetUser = null,
        ?Document $document = null,
        ?string $documentTitle = null,
        ?string $details = null,
        ?string $ipAddress = null,
        ?int $invitationId = null,
        ?array $metadata = null
    ): AuditLog {
        return AuditLog::query()->create([
            'timestamp' => now(),
            'event_type' => $action,
            'action' => $action,
            'performed_by' => 'guest',
            'performed_by_id' => null,
            'actor_id' => null,
            'actor_name' => 'guest',
            'target_user' => $targetUser?->username,
            'target_user_id' => $targetUser?->id,
            'document_title' => $documentTitle ?? $document?->title,
            'document_id' => $document?->id,
            'invitation_id' => $invitationId,
            'details' => $details,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
        ]);
    }
}
