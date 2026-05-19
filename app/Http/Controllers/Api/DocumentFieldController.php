<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentField;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class DocumentFieldController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->fields()
                ->orderBy('page')
                ->orderBy('id')
                ->get()
                ->map(fn (DocumentField $field) => $this->serializeField($document, $field))
                ->values(),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'field_type' => ['nullable', 'in:signature'],
            'page' => ['required', 'integer', 'min:1'],
            'x' => ['required', 'numeric', 'min:0', 'max:100'],
            'y' => ['required', 'numeric', 'min:0', 'max:100'],
            'width' => ['required', 'numeric', 'min:0', 'max:100'],
            'height' => ['required', 'numeric', 'min:0', 'max:100'],
            'required' => ['nullable', 'boolean'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'assigned_recipient_email' => ['nullable', 'email', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $field = DocumentField::query()->create([
            'document_id' => $document->id,
            'invitation_id' => $data['invitation_id'] ?? null,
            'assigned_recipient_email' => $data['assigned_recipient_email'] ?? null,
            'field_type' => $data['field_type'] ?? 'signature',
            'page' => $data['page'],
            'x' => $data['x'],
            'y' => $data['y'],
            'width' => $data['width'],
            'height' => $data['height'],
            'required' => $data['required'] ?? true,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->auditLogger->fromRequest(
            action: 'signature_field_created',
            request: $request,
            document: $document,
            details: sprintf('Created %s field on "%s".', $field->field_type, $document->title)
        );

        return response()->json([
            'message' => 'Field created successfully.',
            'data' => $this->serializeField($document, $field),
        ], 201);
    }

    public function update(Request $request, DocumentField $field)
    {
        $field->loadMissing('document');
        $this->authorize('update', $field->document);

        $data = $request->validate([
            'field_type' => ['nullable', 'in:signature'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'x' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'y' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'width' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'height' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'required' => ['sometimes', 'boolean'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'assigned_recipient_email' => ['nullable', 'email', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $field->fill($data)->save();

        $this->auditLogger->fromRequest(
            action: 'signature_field_updated',
            request: $request,
            document: $field->document,
            details: sprintf('Updated field #%s on "%s".', $field->id, $field->document->title)
        );

        return response()->json([
            'message' => 'Field updated successfully.',
            'data' => $this->serializeField($field->document, $field),
        ]);
    }

    public function destroy(Request $request, DocumentField $field)
    {
        $field->loadMissing('document');
        $this->authorize('update', $field->document);

        $document = $field->document;
        $field->delete();

        $this->auditLogger->fromRequest(
            action: 'signature_field_deleted',
            request: $request,
            document: $document,
            details: sprintf('Deleted field #%s from "%s".', $field->id, $document->title)
        );

        return response()->json([
            'message' => 'Field deleted successfully.',
        ]);
    }

    private function serializeField(Document $document, DocumentField $field): array
    {
        return [
            'id' => (string) $field->id,
            'documentId' => $document->document_uuid,
            'invitationId' => $field->invitation_id ? (string) $field->invitation_id : null,
            'assignedRecipientEmail' => $field->assigned_recipient_email,
            'fieldType' => $field->field_type,
            'page' => $field->page,
            'x' => (string) $field->x,
            'y' => (string) $field->y,
            'width' => (string) $field->width,
            'height' => (string) $field->height,
            'required' => (bool) $field->required,
            'metadata' => $field->metadata,
        ];
    }
}

