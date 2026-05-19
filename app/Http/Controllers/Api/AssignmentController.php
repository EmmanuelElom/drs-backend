<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SignatureResource;
use App\Models\Document;
use App\Models\DocumentAssignment;
use App\Models\DocumentField;
use App\Models\Signature;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentCompletionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AssignmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentCompletionNotifier $completionNotifier
    )
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scope' => ['nullable', 'in:mine,all'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = DocumentAssignment::query()
            ->with(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])
            ->orderByDesc('id');

        if ($user->role !== 'admin' || ($data['scope'] ?? null) === 'mine') {
            $query->where('user_id', $user->id);
        }

        $query->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status));
        $query->when($data['search'] ?? null, function ($query, string $search) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->whereHas('document', function ($documentQuery) use ($search) {
                    $documentQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%");
                })->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        });

        if ($request->boolean('all') || (($data['scope'] ?? null) === 'all' && $user->role === 'admin')) {
            return response()->json([
                'data' => $query->get()->map(fn (DocumentAssignment $assignment) => $this->serializeAssignment($assignment))->values(),
            ]);
        }

        $perPage = (int) ($data['perPage'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (DocumentAssignment $assignment) => $this->serializeAssignment($assignment))
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

    public function show(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);
        $this->authorizeAssignmentView($request, $assignment);

        return response()->json([
            'data' => $this->serializeAssignment($assignment),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('reassign', $document);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'days_allowed' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $assignedUser = User::query()->findOrFail($data['user_id']);
        $assignment = $document->assignments()->latest('id')->first();
        $now = now();
        $daysAllowed = isset($data['days_allowed']) ? (int) $data['days_allowed'] : ($document->days_allowed ? (int) $document->days_allowed : null);
        $expiresAt = ! empty($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : ($daysAllowed ? $now->copy()->addDays($daysAllowed) : $document->expires_at);

        $document->forceFill([
            'user_id' => $assignedUser->id,
            'assigned_by_id' => $request->user()->id,
            'assigned_at' => $now,
            'sent_at' => $now,
            'days_allowed' => $daysAllowed,
            'expires_at' => $expiresAt,
            'review_acknowledged' => false,
            'acknowledged_at' => null,
            'signature_invited' => false,
            'signature_invited_at' => null,
            'signature_completed' => false,
            'signature_completed_at' => null,
            'completed_at' => null,
            'status' => 'in-review',
        ])->save();

        if ($assignment) {
            $assignment->forceFill([
                'user_id' => $assignedUser->id,
                'assigned_by' => $request->user()->id,
                'assigned_at' => $now,
                'expires_at' => $expiresAt,
                'days_allowed' => $daysAllowed,
                'review_acknowledged' => false,
                'acknowledged_at' => null,
                'signature_invited' => false,
                'signature_invited_at' => null,
                'signature_completed' => false,
                'signature_completed_at' => null,
                'status' => 'in-review',
            ])->save();
        } else {
            $assignment = DocumentAssignment::query()->create([
                'document_id' => $document->id,
                'user_id' => $assignedUser->id,
                'assigned_by' => $request->user()->id,
                'assigned_at' => $now,
                'expires_at' => $expiresAt,
                'days_allowed' => $daysAllowed,
                'review_acknowledged' => false,
                'acknowledged_at' => null,
                'signature_invited' => false,
                'signature_invited_at' => null,
                'signature_completed' => false,
                'signature_completed_at' => null,
                'status' => 'in-review',
            ]);
        }

        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $assignedUser,
            document: $document,
            details: sprintf('Assigned "%s" to %s.', $document->title, $assignedUser->username)
        );

        return response()->json([
            'message' => 'Assignment created successfully.',
            'data' => $this->serializeAssignment($assignment),
        ], 201);
    }

    public function acknowledgeReview(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);
        $document = $assignment->document;
        $this->authorize('acknowledge', $document);

        $assignment->forceFill([
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->forceFill([
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'review_completed',
            request: $request,
            targetUser: $assignment->user,
            document: $document,
            details: sprintf('Marked "%s" as reviewed.', $document->title)
        );

        $this->completionNotifier->notify(
            request: $request,
            document: $document,
            completionType: 'review_completed',
            actorName: $assignment->user?->username ?? $request->user()->username
        );

        return response()->json([
            'message' => 'Review acknowledged successfully.',
            'data' => $this->serializeAssignment($assignment->fresh(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])),
        ]);
    }

    public function inviteSignature(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);
        $document = $assignment->document;
        $this->authorize('inviteSignature', $document);

        $assignment->forceFill([
            'signature_invited' => true,
            'signature_invited_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->forceFill([
            'signature_invited' => true,
            'signature_invited_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'signature_invited',
            request: $request,
            targetUser: $assignment->user,
            document: $document,
            details: sprintf('Invited %s to sign "%s".', $assignment->user?->username, $document->title)
        );

        return response()->json([
            'message' => 'Signature invitation flagged successfully.',
            'data' => $this->serializeAssignment($assignment->fresh(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])),
        ]);
    }

    public function completeSignature(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'document.fields', 'user', 'assignedByUser']);
        $document = $assignment->document;
        $this->authorize('sign', $document);

        $data = $request->validate([
            'signature_data' => ['required', 'string'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'signer_email' => ['nullable', 'email', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'metadata' => ['nullable', 'array'],
            'document_field_id' => ['nullable', 'exists:document_fields,id'],
        ]);

        $fieldId = $data['document_field_id'] ?? null;
        if ($fieldId) {
            $field = DocumentField::query()->findOrFail($fieldId);
            abort_unless($field->document_id === $document->id, 422, 'The signature field must belong to the same document.');
        } else {
            $field = $document->fields->firstWhere('field_type', 'signature')
                ?? $document->fields->first();
        }

        $signature = Signature::query()->create([
            'document_id' => $document->id,
            'invitation_id' => null,
            'document_field_id' => $field?->id,
            'user_id' => $assignment->user_id,
            'signer_name' => $data['signer_name'] ?? $assignment->user?->username,
            'signer_email' => $data['signer_email'] ?? $assignment->user?->email,
            'signature_data' => $data['signature_data'],
            'signed_at' => now(),
            'ip_address' => $data['ip_address'] ?? $request->ip(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $assignment->forceFill([
            'signature_completed' => true,
            'signature_completed_at' => now(),
            'status' => 'signed',
        ])->save();

        $document->forceFill([
            'status' => 'signed',
            'signature_completed' => true,
            'signature_completed_at' => now(),
            'completed_at' => now(),
        ])->save();

        $signature->loadMissing(['document', 'user', 'documentField']);

        $this->auditLogger->fromRequest(
            action: 'signature_added',
            request: $request,
            targetUser: $assignment->user,
            document: $document,
            details: sprintf('Signed "%s" by %s.', $document->title, $signature->signer_name)
        );

        $this->auditLogger->fromRequest(
            action: 'signing_completed',
            request: $request,
            targetUser: $assignment->user,
            document: $document,
            details: sprintf('Signing completed for %s.', $document->title)
        );

        $this->completionNotifier->notify(
            request: $request,
            document: $document,
            completionType: 'signing_completed',
            actorName: $signature->signer_name
        );

        return response()->json([
            'message' => 'Signature saved successfully.',
            'data' => [
                'assignment' => $this->serializeAssignment($assignment->fresh(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])),
                'signature' => (new SignatureResource($signature))->toArray($request),
            ],
        ], 201);
    }

    public function updateReviewPeriod(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);
        $document = $assignment->document;
        $this->authorize('updateDays', $document);

        $data = $request->validate([
            'days_allowed' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $daysAllowed = isset($data['days_allowed']) ? (int) $data['days_allowed'] : $assignment->days_allowed;
        $startDate = $assignment->assigned_at ?? $document->assigned_at ?? now();
        $expiresAt = ! empty($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : ($daysAllowed ? $startDate->copy()->addDays($daysAllowed) : $assignment->expires_at);

        $assignment->forceFill([
            'days_allowed' => $daysAllowed,
            'expires_at' => $expiresAt,
        ])->save();

        $document->forceFill([
            'days_allowed' => $daysAllowed,
            'expires_at' => $expiresAt,
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'review_period_updated',
            request: $request,
            targetUser: $assignment->user,
            document: $document,
            details: sprintf('Updated review period for "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Review period updated successfully.',
            'data' => $this->serializeAssignment($assignment->fresh(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])),
        ]);
    }

    public function reassign(Request $request, DocumentAssignment $assignment)
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);
        $document = $assignment->document;
        $this->authorize('reassign', $document);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'days_allowed' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $newUser = User::query()->findOrFail($data['user_id']);
        $daysAllowed = isset($data['days_allowed']) ? (int) $data['days_allowed'] : $assignment->days_allowed;
        $expiresAt = ! empty($data['expires_at'])
            ? now()->parse($data['expires_at'])
            : ($daysAllowed ? now()->copy()->addDays($daysAllowed) : $assignment->expires_at);

        $assignment->forceFill([
            'user_id' => $newUser->id,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'expires_at' => $expiresAt,
            'days_allowed' => $daysAllowed,
            'review_acknowledged' => false,
            'acknowledged_at' => null,
            'signature_invited' => false,
            'signature_invited_at' => null,
            'signature_completed' => false,
            'signature_completed_at' => null,
            'status' => 'in-review',
        ])->save();

        $document->forceFill([
            'user_id' => $newUser->id,
            'assigned_by_id' => $request->user()->id,
            'assigned_at' => now(),
            'sent_at' => now(),
            'expires_at' => $expiresAt,
            'days_allowed' => $daysAllowed,
            'review_acknowledged' => false,
            'acknowledged_at' => null,
            'signature_invited' => false,
            'signature_invited_at' => null,
            'signature_completed' => false,
            'signature_completed_at' => null,
            'completed_at' => null,
            'status' => 'in-review',
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'document_reassigned',
            request: $request,
            targetUser: $newUser,
            document: $document,
            details: sprintf('Reassigned "%s" to %s.', $document->title, $newUser->username)
        );

        return response()->json([
            'message' => 'Document reassigned successfully.',
            'data' => $this->serializeAssignment($assignment->fresh(['document.owner', 'document.createdBy', 'user', 'assignedByUser'])),
        ]);
    }

    private function serializeAssignment(DocumentAssignment $assignment): array
    {
        $assignment->loadMissing(['document.owner', 'document.createdBy', 'user', 'assignedByUser']);

        return (new AssignmentResource($assignment))->toArray(request());
    }

    private function authorizeAssignmentView(Request $request, DocumentAssignment $assignment): void
    {
        $user = $request->user();
        $document = $assignment->document;

        abort_unless(
            $user->role === 'admin'
            || (string) $assignment->user_id === (string) $user->id
            || (string) $document->owner_id === (string) $user->id
            || (string) $document->created_by_id === (string) $user->id,
            403,
            'You are not allowed to view this assignment.'
        );
    }
}
