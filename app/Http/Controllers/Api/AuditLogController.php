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
            'performed_by' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'document_title' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = AuditLog::query()
            ->with(['performer', 'targetUser', 'document'])
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('action', 'like', "%{$search}%")
                        ->orWhere('performed_by', 'like', "%{$search}%")
                        ->orWhere('target_user', 'like', "%{$search}%")
                        ->orWhere('document_title', 'like', "%{$search}%")
                        ->orWhere('details', 'like', "%{$search}%");
                });
            })
            ->when($data['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($data['performed_by'] ?? null, fn ($query, string $performedBy) => $query->where('performed_by', 'like', "%{$performedBy}%"))
            ->when($data['target_user'] ?? null, fn ($query, string $targetUser) => $query->where('target_user', 'like', "%{$targetUser}%"))
            ->when($data['document_title'] ?? null, fn ($query, string $documentTitle) => $query->where('document_title', 'like', "%{$documentTitle}%"))
            ->when($data['from'] ?? null, fn ($query, string $from) => $query->whereDate('timestamp', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, string $to) => $query->whereDate('timestamp', '<=', $to))
            ->orderByDesc('timestamp');

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->limit(200)->get()->map(fn (AuditLog $log) => $this->serializeAuditLog($log))->values(),
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

    private function serializeAuditLog(AuditLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'timestamp' => optional($log->timestamp)->toISOString(),
            'action' => $log->action,
            'performedBy' => $log->performed_by,
            'performedById' => (string) $log->performed_by_id,
            'targetUser' => $log->target_user,
            'targetUserId' => $log->target_user_id ? (string) $log->target_user_id : null,
            'documentTitle' => $log->document_title,
            'documentId' => $log->document_id ? (string) $log->document_id : null,
            'details' => $log->details,
            'ipAddress' => $log->ip_address,
        ];
    }
}
