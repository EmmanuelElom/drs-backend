<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
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
        ?string $ipAddress = null
    ): AuditLog {
        return AuditLog::query()->create([
            'timestamp' => now(),
            'action' => $action,
            'performed_by' => $performedBy->username,
            'performed_by_id' => $performedBy->id,
            'target_user' => $targetUser?->username,
            'target_user_id' => $targetUser?->id,
            'document_title' => $documentTitle ?? $document?->title,
            'document_id' => $document?->id,
            'details' => $details,
            'ip_address' => $ipAddress,
        ]);
    }

    public function fromRequest(
        string $action,
        Request $request,
        ?User $targetUser = null,
        ?Document $document = null,
        ?string $documentTitle = null,
        ?string $details = null
    ): AuditLog {
        return $this->record(
            action: $action,
            performedBy: $request->user(),
            targetUser: $targetUser,
            document: $document,
            documentTitle: $documentTitle,
            details: $details,
            ipAddress: $request->ip()
        );
    }
}
