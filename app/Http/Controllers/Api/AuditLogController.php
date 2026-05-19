<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'actor_name' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'document_title' => ['nullable', 'string', 'max:255'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = AuditLog::query()
            ->with(['performer', 'targetUser', 'document'])
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('action', 'like', "%{$search}%")
                        ->orWhere('performed_by', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhere('target_user', 'like', "%{$search}%")
                        ->orWhere('document_title', 'like', "%{$search}%")
                        ->orWhere('details', 'like', "%{$search}%");
                });
            })
            ->when($data['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($data['event_type'] ?? null, fn ($query, string $eventType) => $query->where('event_type', $eventType))
            ->when($data['performed_by'] ?? null, fn ($query, string $performedBy) => $query->where('performed_by', 'like', "%{$performedBy}%"))
            ->when($data['actor_name'] ?? null, fn ($query, string $actorName) => $query->where('actor_name', 'like', "%{$actorName}%"))
            ->when($data['target_user'] ?? null, fn ($query, string $targetUser) => $query->where('target_user', 'like', "%{$targetUser}%"))
            ->when($data['document_title'] ?? null, fn ($query, string $documentTitle) => $query->where('document_title', 'like', "%{$documentTitle}%"))
            ->when($data['from'] ?? null, fn ($query, string $from) => $query->whereDate('timestamp', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, string $to) => $query->whereDate('timestamp', '<=', $to))
            ->when($data['dateFrom'] ?? null, fn ($query, string $from) => $query->whereDate('timestamp', '>=', $from))
            ->when($data['dateTo'] ?? null, fn ($query, string $to) => $query->whereDate('timestamp', '<=', $to))
            ->when($data['invitation_id'] ?? null, fn ($query, string $invitationId) => $query->where('invitation_id', $invitationId))
            ->orderByDesc('timestamp');

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->get()->map(fn (AuditLog $log) => $this->serializeAuditLog($log))->values(),
            ]);
        }

        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (AuditLog $log) => $this->serializeAuditLog($log))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog)
    {
        $this->authorize('view', $auditLog);

        $auditLog->load(['performer', 'targetUser', 'document']);

        return response()->json([
            'data' => $this->serializeAuditLog($auditLog),
        ]);
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'actor_name' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'document_title' => ['nullable', 'string', 'max:255'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date'],
        ]);

        $query = $this->buildQuery($data);
        $logs = $query->get();
        $lines = [
            'id,timestamp,eventType,action,performedBy,performedById,actorName,actorId,targetUser,targetUserId,documentTitle,documentId,invitationId,details,ipAddress',
        ];

        foreach ($logs as $log) {
            $lines[] = implode(',', [
                $this->csvValue($log->id),
                $this->csvValue(optional($log->timestamp)->toISOString()),
                $this->csvValue($log->event_type),
                $this->csvValue($log->action),
                $this->csvValue($log->performed_by),
                $this->csvValue($log->performed_by_id),
                $this->csvValue($log->actor_name),
                $this->csvValue($log->actor_id),
                $this->csvValue($log->target_user),
                $this->csvValue($log->target_user_id),
                $this->csvValue($log->document_title),
                $this->csvValue($log->document_id),
                $this->csvValue($log->invitation_id),
                $this->csvValue($log->details),
                $this->csvValue($log->ip_address),
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-logs.csv"',
        ]);
    }

    public function destroy(Request $request)
    {
        $this->authorize('deleteAny', AuditLog::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
        ]);

        AuditLog::query()->whereIn('id', $data['ids'])->delete();

        return response()->json([
            'message' => 'Audit logs deleted successfully.',
        ]);
    }

    private function buildQuery(array $data)
    {
        return AuditLog::query()
            ->with(['performer', 'targetUser', 'document'])
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('action', 'like', "%{$search}%")
                        ->orWhere('performed_by', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhere('target_user', 'like', "%{$search}%")
                        ->orWhere('document_title', 'like', "%{$search}%")
                        ->orWhere('details', 'like', "%{$search}%");
                });
            })
            ->when($data['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($data['event_type'] ?? null, fn ($query, string $eventType) => $query->where('event_type', $eventType))
            ->when($data['performed_by'] ?? null, fn ($query, string $performedBy) => $query->where('performed_by', 'like', "%{$performedBy}%"))
            ->when($data['actor_name'] ?? null, fn ($query, string $actorName) => $query->where('actor_name', 'like', "%{$actorName}%"))
            ->when($data['target_user'] ?? null, fn ($query, string $targetUser) => $query->where('target_user', 'like', "%{$targetUser}%"))
            ->when($data['document_title'] ?? null, fn ($query, string $documentTitle) => $query->where('document_title', 'like', "%{$documentTitle}%"))
            ->when($data['from'] ?? null, fn ($query, string $from) => $query->whereDate('timestamp', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, string $to) => $query->whereDate('timestamp', '<=', $to))
            ->when($data['dateFrom'] ?? null, fn ($query, string $from) => $query->whereDate('timestamp', '>=', $from))
            ->when($data['dateTo'] ?? null, fn ($query, string $to) => $query->whereDate('timestamp', '<=', $to))
            ->when($data['invitation_id'] ?? null, fn ($query, string $invitationId) => $query->where('invitation_id', $invitationId))
            ->orderByDesc('timestamp');
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function serializeAuditLog(AuditLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'timestamp' => optional($log->timestamp)->toISOString(),
            'eventType' => $log->event_type,
            'action' => $log->action,
            'performedBy' => $log->performed_by,
            'performedById' => $log->performed_by_id ? (string) $log->performed_by_id : null,
            'actorName' => $log->actor_name,
            'actorId' => $log->actor_id ? (string) $log->actor_id : null,
            'targetUser' => $log->target_user,
            'targetUserId' => $log->target_user_id ? (string) $log->target_user_id : null,
            'documentTitle' => $log->document_title,
            'documentId' => $log->document_id ? (string) $log->document_id : null,
            'invitationId' => $log->invitation_id ? (string) $log->invitation_id : null,
            'details' => $log->details,
            'metadata' => $log->metadata,
            'ipAddress' => $log->ip_address,
        ];
    }
}
