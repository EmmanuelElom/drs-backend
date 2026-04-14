<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private const STORAGE_SETTING_KEY = 'document_storage_mode';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,in-review,reviewed,signed'],
            'storage_mode' => ['nullable', 'in:base64,upload,auto'],
            'user_id' => ['nullable', 'exists:users,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = Document::query()
            ->with('user')
            ->when($user->role !== 'admin', fn ($query) => $query->where('user_id', $user->id))
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%");
                });
            })
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['storage_mode'] ?? null, fn ($query, string $storageMode) => $query->where('storage_mode', $storageMode))
            ->when($data['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            $documents = $query->get()->map(fn (Document $document) => $this->serializeDocument($document))->values();

            return response()->json([
                'data' => $documents,
            ]);
        }

        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Document $document) => $this->serializeDocument($document))
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

    public function store(Request $request)
    {
        $this->authorize('create', Document::class);

        $storageMode = $this->getStorageMode();

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'days_allowed' => ['required', 'integer', 'min:1'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'file_type' => ['nullable', 'string', 'max:100'],
            'file_data' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $uploadedFile = $request->file('file');
        $hasUpload = $uploadedFile instanceof UploadedFile && $uploadedFile->isValid();
        $hasInlineFile = filled($data['file_data'] ?? null);

        if ($hasUpload || $hasInlineFile) {
            if ($storageMode === 'upload' && ! $hasUpload) {
                abort(422, 'A PDF upload is required for the current storage mode.');
            }

            if ($storageMode === 'base64' && ! $hasInlineFile) {
                abort(422, 'Base64 file data is required for the current storage mode.');
            }
        } elseif (! filled($data['content'] ?? null)) {
            abort(422, 'The content field is required.');
        }

        $daysAllowed = (int) $data['days_allowed'];
        $assignedAt = now();
        $storedFile = $hasUpload ? $this->storeUploadedFile($uploadedFile) : null;

        $document = Document::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'user_id' => $data['user_id'],
            'assigned_by_id' => $request->user()->id,
            'title' => $data['title'],
            'content' => $data['content'] ?? '',
            'file_name' => $hasUpload ? $storedFile['file_name'] : ($data['file_name'] ?? null),
            'file_size' => $hasUpload ? $storedFile['file_size'] : ($data['file_size'] ?? null),
            'file_type' => $hasUpload ? $storedFile['file_type'] : ($data['file_type'] ?? null),
            'file_data' => $hasUpload ? null : ($data['file_data'] ?? null),
            'file_disk' => $storedFile['file_disk'] ?? null,
            'file_path' => $storedFile['file_path'] ?? null,
            'storage_mode' => $hasUpload ? 'upload' : ($hasInlineFile ? 'base64' : $storageMode),
            'days_allowed' => $daysAllowed,
            'assigned_at' => $assignedAt,
            'expires_at' => $assignedAt->copy()->addDays($daysAllowed),
            'status' => 'in-review',
            'review_acknowledged' => false,
            'signature_invited' => false,
        ]);

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Assigned "%s" to %s.', $document->title, $document->user?->username)
        );

        $this->auditLogger->fromRequest(
            action: 'document_uploaded',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Uploaded "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Document created successfully.',
            'data' => $this->serializeDocument($document),
        ], 201);
    }

    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $document->load(['user', 'assignedBy', 'comments.user', 'signatures.user']);

        return response()->json([
            'data' => $this->serializeDocument($document, true),
        ]);
    }

    public function downloadFile(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        if (! $document->file_path) {
            abort(404, 'File not found.');
        }

        $disk = $document->file_disk ?: config('filesystems.default');

        if (! Storage::disk($disk)->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->file_name ?? basename($document->file_path)
        );
    }

    public function acknowledge(Request $request, Document $document)
    {
        $this->authorize('acknowledge', $document);

        $document->forceFill([
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'review_completed',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Marked "%s" as reviewed.', $document->title)
        );

        return response()->json([
            'message' => 'Review acknowledged successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function inviteSignature(Request $request, Document $document)
    {
        $this->authorize('inviteSignature', $document);

        $document->forceFill([
            'signature_invited' => true,
            'signature_invited_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'signature_invited',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Invited %s to sign "%s".', $document->user?->username, $document->title)
        );

        return response()->json([
            'message' => 'Signature invitation sent successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function reassign(Request $request, Document $document)
    {
        $this->authorize('reassign', $document);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $previousUser = $document->user;
        $newUser = User::query()->findOrFail($data['user_id']);

        $daysAllowed = (int) $document->days_allowed;

        $document->forceFill([
            'user_id' => $newUser->id,
            'assigned_at' => now(),
            'expires_at' => now()->copy()->addDays($daysAllowed),
            'review_acknowledged' => false,
            'acknowledged_at' => null,
            'signature_invited' => false,
            'signature_invited_at' => null,
            'status' => 'in-review',
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $newUser,
            document: $document,
            details: sprintf(
                'Reassigned "%s" from %s to %s.',
                $document->title,
                $previousUser?->username,
                $newUser->username
            )
        );

        return response()->json([
            'message' => 'Document reassigned successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function updateDays(Request $request, Document $document)
    {
        $this->authorize('updateDays', $document);

        $data = $request->validate([
            'days_allowed' => ['required', 'integer', 'min:1'],
        ]);

        $startDate = $document->assigned_at ?? now();

        $daysAllowed = (int) $data['days_allowed'];

        $document->forceFill([
            'days_allowed' => $daysAllowed,
            'expires_at' => $startDate->copy()->addDays($daysAllowed),
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Updated review days for "%s" to %d.', $document->title, $document->days_allowed)
        );

        return response()->json([
            'message' => 'Review period updated successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function updateStatus(Request $request, Document $document)
    {
        $this->authorize('updateStatus', $document);

        $data = $request->validate([
            'status' => ['required', 'in:pending,in-review,reviewed,signed'],
        ]);

        $document->forceFill([
            'status' => $data['status'],
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'review_completed',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Updated status for "%s" to %s.', $document->title, $document->status)
        );

        return response()->json([
            'message' => 'Document status updated successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorize('delete', $document);

        $title = $document->title;
        $targetUser = $document->user;
        $this->deleteStoredFile($document);

        $document->delete();

        $this->auditLogger->fromRequest(
            action: 'document_deleted',
            request: $request,
            targetUser: $targetUser,
            documentTitle: $title,
            details: sprintf('Deleted document "%s".', $title)
        );

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }

    private function serializeDocument(Document $document, bool $includeRelations = false): array
    {
        return [
            'id' => (string) $document->id,
            'documentId' => $document->document_uuid,
            'userId' => (string) $document->user_id,
            'username' => $document->relationLoaded('user') && $document->user ? $document->user->username : null,
            'assignedAt' => optional($document->assigned_at)->toISOString(),
            'expiresAt' => optional($document->expires_at)->toISOString(),
            'daysAllowed' => $document->days_allowed,
            'title' => $document->title,
            'content' => $document->content,
            'fileType' => $document->file_type,
            'fileData' => $document->file_data,
            'reviewAcknowledged' => (bool) $document->review_acknowledged,
            'acknowledgedAt' => optional($document->acknowledged_at)->toISOString(),
            'signatureInvited' => (bool) $document->signature_invited,
            'signatureInvitedAt' => optional($document->signature_invited_at)->toISOString(),
            'status' => $document->status,
            'fileName' => $document->file_name,
            'fileSize' => $document->file_size,
            'assignedBy' => $document->relationLoaded('assignedBy') && $document->assignedBy ? $document->assignedBy->username : null,
            'comments' => $includeRelations && $document->relationLoaded('comments')
                ? $document->comments->map(fn ($comment) => [
                    'id' => (string) $comment->id,
                    'documentId' => $document->document_uuid,
                    'userId' => (string) $comment->user_id,
                    'username' => $comment->user?->username,
                    'selectedText' => $comment->selected_text,
                    'comment' => $comment->comment,
                    'timestamp' => optional($comment->created_at)->toISOString(),
                ])->values()
                : [],
            'signatures' => $includeRelations && $document->relationLoaded('signatures')
                ? $document->signatures->map(fn ($signature) => [
                    'id' => (string) $signature->id,
                    'documentId' => $document->document_uuid,
                    'userId' => (string) $signature->user_id,
                    'username' => $signature->user?->username,
                    'signatureData' => $signature->signature_data,
                    'signedAt' => optional($signature->signed_at)->toISOString(),
                    'ipAddress' => $signature->ip_address,
                ])->values()
                : [],
            'storageMode' => $document->storage_mode,
            'fileUrl' => $document->file_path ? url("/api/documents/{$document->document_uuid}/file") : null,
            'fileData' => $this->resolveDocumentFileData($document),
        ];
    }

    private function getStorageMode(): string
    {
        if (! Schema::hasTable('app_settings')) {
            return 'auto';
        }

        $setting = AppSetting::query()
            ->where('key', self::STORAGE_SETTING_KEY)
            ->value('value');

        return in_array($setting, ['base64', 'upload', 'auto'], true) ? $setting : 'auto';
    }

    private function storeUploadedFile(UploadedFile $file): array
    {
        $disk = config('filesystems.default', 'local');
        $fileName = $file->getClientOriginalName();
        $fileType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/pdf';
        $fileSize = $file->getSize() ?: 0;
        $safeName = Str::uuid()->toString() . '.pdf';
        $filePath = $file->storeAs('documents', $safeName, $disk);

        return [
            'file_disk' => $disk,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_type' => $fileType,
        ];
    }

    private function resolveDocumentFileData(Document $document): ?string
    {
        if ($document->file_data) {
            return $document->file_data;
        }

        if (! $document->file_path) {
            return null;
        }

        $disk = $document->file_disk ?: config('filesystems.default');

        if (! Storage::disk($disk)->exists($document->file_path)) {
            return null;
        }

        $mimeType = $document->file_type ?: Storage::disk($disk)->mimeType($document->file_path) ?: 'application/pdf';
        $contents = Storage::disk($disk)->get($document->file_path);

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    private function deleteStoredFile(Document $document): void
    {
        if (! $document->file_path) {
            return;
        }

        $disk = $document->file_disk ?: config('filesystems.default');

        if (Storage::disk($disk)->exists($document->file_path)) {
            Storage::disk($disk)->delete($document->file_path);
        }
    }
}
