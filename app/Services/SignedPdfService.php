<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAssignment;
use App\Models\DocumentField;
use App\Models\DocumentInvitation;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class SignedPdfService
{
    /**
     * @return Collection<int, DocumentField>
     */
    public function resolveSignatureFieldsForInvitation(Document $document, DocumentInvitation $invitation): Collection
    {
        $document->loadMissing(['fields', 'invitations']);

        $signatureFields = $document->fields
            ->filter(fn (DocumentField $field) => $field->field_type === 'signature')
            ->values();

        $normalizedEmail = $this->normalizeEmail($invitation->recipient_email);

        $explicitFields = $signatureFields->filter(function (DocumentField $field) use ($invitation, $normalizedEmail) {
            if ((string) $field->invitation_id === (string) $invitation->id) {
                return true;
            }

            if (! filled($field->assigned_recipient_email) || ! filled($normalizedEmail)) {
                return false;
            }

            return $this->normalizeEmail($field->assigned_recipient_email) === $normalizedEmail;
        })->values();

        if ($explicitFields->isNotEmpty()) {
            return $this->sortFields($explicitFields);
        }

        if ($this->hasSingleSignInvitation($document)) {
            $legacyFields = $signatureFields->filter(function (DocumentField $field) {
                return blank($field->invitation_id) && blank($field->assigned_recipient_email);
            })->values();

            if ($legacyFields->isNotEmpty()) {
                return $this->sortFields($legacyFields);
            }
        }

        return collect();
    }

    /**
     * @return Collection<int, DocumentField>
     */
    public function resolveSignatureFieldsForSigner(Document $document, ?string $recipientEmail = null, ?int $invitationId = null): Collection
    {
        $document->loadMissing(['fields', 'invitations']);

        $signatureFields = $document->fields
            ->filter(fn (DocumentField $field) => $field->field_type === 'signature')
            ->values();

        $normalizedEmail = $this->normalizeEmail($recipientEmail);

        $explicitFields = $signatureFields->filter(function (DocumentField $field) use ($normalizedEmail, $invitationId) {
            if ($invitationId && (string) $field->invitation_id === (string) $invitationId) {
                return true;
            }

            if (filled($field->invitation_id) && filled($normalizedEmail)) {
                $linkedInvitation = $document->invitations->firstWhere('id', $field->invitation_id);

                if ($linkedInvitation && $this->normalizeEmail($linkedInvitation->recipient_email) === $normalizedEmail) {
                    return true;
                }
            }

            if (! filled($field->assigned_recipient_email) || ! filled($normalizedEmail)) {
                return false;
            }

            return $this->normalizeEmail($field->assigned_recipient_email) === $normalizedEmail;
        })->values();

        if ($explicitFields->isNotEmpty()) {
            return $this->sortFields($explicitFields);
        }

        if ($this->hasSingleSignInvitation($document)) {
            $legacyFields = $signatureFields->filter(function (DocumentField $field) {
                return blank($field->invitation_id) && blank($field->assigned_recipient_email);
            })->values();

            if ($legacyFields->isNotEmpty()) {
                return $this->sortFields($legacyFields);
            }
        }

        return collect();
    }

    /**
     * @return Collection<int, Signature>
     */
    public function resolveAccessibleSignaturesForInvitation(Document $document, DocumentInvitation $invitation): Collection
    {
        $document->loadMissing(['signatures.user', 'signatures.invitation', 'signatures.documentField', 'fields', 'invitations']);

        if ($this->shouldShareSignaturesWithSigners($document)) {
            return $this->sortSignatures($document->signatures->values());
        }

        $accessibleFieldIds = $this->resolveSignatureFieldsForInvitation($document, $invitation)->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($accessibleFieldIds === []) {
            return collect();
        }

        return $document->signatures
            ->filter(function (Signature $signature) use ($accessibleFieldIds, $invitation) {
                return in_array((string) $signature->document_field_id, $accessibleFieldIds, true)
                    && (string) $signature->invitation_id === (string) $invitation->id;
            })
            ->values();
    }

    /**
     * @return Collection<int, Signature>
     */
    public function resolveAccessibleSignaturesForUser(Document $document, ?User $user): Collection
    {
        $document->loadMissing(['signatures.user', 'signatures.invitation', 'signatures.documentField', 'fields', 'invitations']);

        if (! $user) {
            return collect();
        }

        if ($this->canUserSeeAllSignatures($document, $user)) {
            return $this->sortSignatures($document->signatures->values());
        }

        $visibleFieldIds = $this->resolveSignatureFieldsForSigner($document, $user->email, null)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($visibleFieldIds === []) {
            return $this->sortSignatures(
                $document->signatures
                    ->filter(function (Signature $signature) use ($user) {
                        return (string) $signature->user_id === (string) $user->id
                            || $this->normalizeEmail($signature->signer_email) === $this->normalizeEmail($user->email);
                    })
                    ->values()
            );
        }

        return $this->sortSignatures(
            $document->signatures
                ->filter(function (Signature $signature) use ($visibleFieldIds) {
                    return in_array((string) $signature->document_field_id, $visibleFieldIds, true);
                })
                ->values()
        );
    }

    public function hasPendingInvitations(Document $document): bool
    {
        $document->loadMissing('invitations');

        return $document->invitations->contains(function (DocumentInvitation $invitation) {
            return ! in_array($invitation->status, ['completed', 'revoked', 'expired'], true);
        });
    }

    public function hasPendingAssignments(Document $document): bool
    {
        $document->loadMissing('assignments');

        return $document->assignments->contains(function (DocumentAssignment $assignment) {
            if (in_array($assignment->status, ['signed', 'revoked', 'expired'], true) || $assignment->signature_completed) {
                return false;
            }

            if ($assignment->signature_invited && ! $assignment->signature_completed) {
                return true;
            }

            return $assignment->status === 'in-review' || ! $assignment->review_acknowledged;
        });
    }

    public function finalizeDocumentIfComplete(Document $document): bool
    {
        $document->loadMissing(['invitations', 'assignments']);

        if ($this->hasPendingInvitations($document) || $this->hasPendingAssignments($document)) {
            return false;
        }

        $hasSigningInvitation = $document->invitations->contains(fn (DocumentInvitation $invitation) => (bool) $invitation->can_sign)
            || $document->assignments->contains(fn (DocumentAssignment $assignment) => (bool) $assignment->signature_invited || (bool) $assignment->signature_completed || $assignment->status === 'signed');
        $hasReviewInvitation = $document->invitations->contains(fn (DocumentInvitation $invitation) => (bool) $invitation->can_review)
            || $document->assignments->contains(fn (DocumentAssignment $assignment) => (bool) $assignment->review_acknowledged || in_array($assignment->status, ['reviewed', 'signed'], true));
        $completedAt = now();

        $document->forceFill([
            'status' => $hasSigningInvitation ? 'signed' : 'reviewed',
            'review_acknowledged' => $hasReviewInvitation ? true : (bool) $document->review_acknowledged,
            'acknowledged_at' => $hasReviewInvitation ? ($document->acknowledged_at ?: $completedAt) : $document->acknowledged_at,
            'signature_completed' => $hasSigningInvitation ? true : (bool) $document->signature_completed,
            'signature_completed_at' => $hasSigningInvitation ? ($document->signature_completed_at ?: $completedAt) : $document->signature_completed_at,
            'completed_at' => $completedAt,
        ])->saveQuietly();

        return true;
    }

    public function ensure(Document $document): ?string
    {
        $document->load(['fields', 'signatures.documentField']);

        $disk = $this->resolveDisk($document);

        if ($document->signed_file_path && Storage::disk($disk)->exists($document->signed_file_path)) {
            return $document->signed_file_path;
        }

        if (! $document->signature_completed && ! $document->signature_completed_at) {
            return null;
        }

        return $this->refresh($document);
    }

    public function refresh(Document $document): ?string
    {
        $document->load(['fields', 'signatures.documentField']);

        $disk = $this->resolveDisk($document);
        $sourcePath = $this->createSourcePdfFile($document, $disk);

        if ($sourcePath === null) {
            return null;
        }

        $signatures = $document->signatures
            ->filter(fn (Signature $signature) => $signature->documentField instanceof DocumentField)
            ->values();

        if ($signatures->isEmpty()) {
            $this->clearSignedFile($document, $disk);
            $this->removeTempFile($sourcePath);

            return null;
        }

        $outputPath = $this->createTempFilePath('.pdf');
        $tempSignatureFiles = [];

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);
            $fieldsByPage = $document->fields
                ->where('field_type', 'signature')
                ->groupBy(fn (DocumentField $field) => max((int) ($field->page ?? 1), 1));
            $signaturesByField = $signatures->groupBy('document_field_id');

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $templateId = $pdf->importPage($pageNumber);
                $pageSize = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($pageSize['orientation'], [$pageSize['width'], $pageSize['height']]);
                $pdf->useTemplate($templateId);

                foreach ($fieldsByPage->get($pageNumber, collect()) as $field) {
                    $fieldSignatures = $signaturesByField->get($field->id);

                    if (! $fieldSignatures || $fieldSignatures->isEmpty()) {
                        continue;
                    }

                    /** @var Signature|null $signature */
                    $signature = $fieldSignatures->sortByDesc('signed_at')->first();
                    if (! $signature) {
                        continue;
                    }

                    $this->stampSignature(
                        pdf: $pdf,
                        signature: $signature,
                        field: $field,
                        pageWidth: (float) $pageSize['width'],
                        pageHeight: (float) $pageSize['height'],
                        tempSignatureFiles: $tempSignatureFiles
                    );
                }
            }

            $pdf->Output('F', $outputPath);
            $signedContents = file_get_contents($outputPath);

            if ($signedContents === false) {
                throw new RuntimeException('Unable to read generated signed PDF.');
            }

            $signedPath = $this->signedDocumentPath($document);
            $signedDisk = $this->resolveSignedDisk($document, $disk);

            if ($document->signed_file_path && $document->signed_file_path !== $signedPath && Storage::disk($signedDisk)->exists($document->signed_file_path)) {
                Storage::disk($signedDisk)->delete($document->signed_file_path);
            }

            Storage::disk($signedDisk)->put($signedPath, $signedContents);

            $document->forceFill([
                'signed_file_disk' => $signedDisk,
                'signed_file_path' => $signedPath,
                'signed_file_generated_at' => now(),
            ])->saveQuietly();

            return $signedPath;
        } finally {
            $this->removeTempFile($sourcePath);
            $this->removeTempFile($outputPath);

            foreach ($tempSignatureFiles as $tempSignatureFile) {
                $this->removeTempFile($tempSignatureFile);
            }
        }
    }

    private function resolveDisk(Document $document): string
    {
        return $document->file_disk ?: config('filesystems.default', 'local');
    }

    private function sortFields(Collection $fields): Collection
    {
        return $fields->sortBy([
            ['page', 'asc'],
            ['id', 'asc'],
        ])->values();
    }

    /**
     * @param Collection<int, Signature> $signatures
     * @return Collection<int, Signature>
     */
    private function sortSignatures(Collection $signatures): Collection
    {
        return $signatures->sortBy([
            ['signed_at', 'asc'],
            ['id', 'asc'],
        ])->values();
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (! filled($email)) {
            return null;
        }

        return Str::lower(trim($email));
    }

    private function hasSingleSignInvitation(Document $document): bool
    {
        $document->loadMissing('invitations');

        return $document->invitations
            ->filter(fn (DocumentInvitation $invitation) => (bool) $invitation->can_sign && ! in_array($invitation->status, ['revoked', 'expired'], true))
            ->count() === 1;
    }

    private function shouldShareSignaturesWithSigners(Document $document): bool
    {
        return (bool) $document->show_signatures_to_signers;
    }

    private function canUserSeeAllSignatures(Document $document, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return (string) $document->owner_id === (string) $user->id
            || (string) $document->created_by_id === (string) $user->id
            || $this->shouldShareSignaturesWithSigners($document);
    }

    private function resolveSignedDisk(Document $document, string $fallbackDisk): string
    {
        return $document->signed_file_disk ?: $fallbackDisk;
    }

    private function signedDocumentPath(Document $document): string
    {
        return sprintf('signed-documents/%s.pdf', $document->document_uuid);
    }

    private function createSourcePdfFile(Document $document, string $disk): ?string
    {
        if ($document->file_path && Storage::disk($disk)->exists($document->file_path)) {
            $sourceContents = Storage::disk($disk)->get($document->file_path);
            $sourceMimeType = $document->file_type ?: Storage::disk($disk)->mimeType($document->file_path) ?: 'application/pdf';

            if (! $this->isPdfMimeType($sourceMimeType) && ! $this->isPdfFileName($document->file_name ?? $document->file_path)) {
                return null;
            }

            return $this->writeTempFile($sourceContents, '.pdf');
        }

        if (! filled($document->file_data)) {
            return null;
        }

        [$mimeType, $contents] = $this->decodeDataUrl($document->file_data);

        if (! $this->isPdfMimeType($mimeType) && ! $this->isPdfFileName($document->file_name ?? null)) {
            return null;
        }

        return $this->writeTempFile($contents, '.pdf');
    }

    /**
     * @param array<int, string> $tempSignatureFiles
     */
    private function stampSignature(Fpdi $pdf, Signature $signature, DocumentField $field, float $pageWidth, float $pageHeight, array &$tempSignatureFiles): void
    {
        $signatureData = trim((string) $signature->signature_data);
        if ($signatureData === '') {
            return;
        }

        [$mimeType, $bytes] = $this->decodeSignatureData($signatureData);
        $extension = $this->extensionForMimeType($mimeType);
        $signatureFile = $this->writeTempFile($bytes, $extension);
        $tempSignatureFiles[] = $signatureFile;

        [$imageWidth, $imageHeight] = getimagesize($signatureFile) ?: [0, 0];
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            return;
        }

        $boxX = $pageWidth * ($this->toFloat($field->x) / 100);
        $boxY = $pageHeight * ($this->toFloat($field->y) / 100);
        $boxWidth = max($pageWidth * ($this->toFloat($field->width) / 100), 24.0);
        $boxHeight = max($pageHeight * ($this->toFloat($field->height) / 100), 12.0);

        $paddingX = max(min($boxWidth * 0.08, 8.0), 2.0);
        $paddingY = max(min($boxHeight * 0.08, 6.0), 2.0);
        $availableWidth = max($boxWidth - ($paddingX * 2), 1.0);
        $availableHeight = max($boxHeight - ($paddingY * 2), 1.0);

        $scale = min($availableWidth / $imageWidth, $availableHeight / $imageHeight, 1.0);
        $drawWidth = $imageWidth * $scale;
        $drawHeight = $imageHeight * $scale;

        $pdf->Image(
            $signatureFile,
            $boxX + (($boxWidth - $drawWidth) / 2),
            $boxY + (($boxHeight - $drawHeight) / 2),
            $drawWidth,
            $drawHeight
        );
    }

    private function decodeSignatureData(string $signatureData): array
    {
        if (! str_starts_with($signatureData, 'data:')) {
            return ['image/png', $this->decodeBase64Payload($signatureData)];
        }

        [$header, $payload] = array_pad(explode(',', $signatureData, 2), 2, '');
        $meta = substr($header, 5);
        $mimeType = 'image/png';

        if ($meta !== '') {
            $mimeType = explode(';', $meta, 2)[0] ?: 'image/png';
        }

        return [$mimeType, $this->decodeBase64Payload($payload)];
    }

    private function decodeDataUrl(string $dataUrl): array
    {
        if (! str_starts_with($dataUrl, 'data:')) {
            return ['application/pdf', $this->decodeBase64Payload($dataUrl)];
        }

        [$header, $payload] = array_pad(explode(',', $dataUrl, 2), 2, '');
        $meta = substr($header, 5);
        $mimeType = 'application/pdf';

        if ($meta !== '') {
            $mimeType = explode(';', $meta, 2)[0] ?: 'application/pdf';
        }

        return [$mimeType, $this->decodeBase64Payload($payload)];
    }

    private function decodeBase64Payload(string $payload): string
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new RuntimeException('Unable to decode base64 payload.');
        }

        return $decoded;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            default => '.png',
        };
    }

    private function isPdfMimeType(?string $mimeType): bool
    {
        if (! filled($mimeType)) {
            return false;
        }

        return str_contains(strtolower($mimeType), 'pdf');
    }

    private function isPdfFileName(?string $fileName): bool
    {
        return filled($fileName) && str_ends_with(strtolower($fileName), '.pdf');
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function writeTempFile(string $contents, string $extension): string
    {
        $basePath = tempnam(sys_get_temp_dir(), 'pdf');

        if ($basePath === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        $tempPath = $basePath . $extension;

        if (! rename($basePath, $tempPath)) {
            @unlink($basePath);
            throw new RuntimeException('Unable to prepare a temporary file.');
        }

        if (file_put_contents($tempPath, $contents) === false) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to write to the temporary file.');
        }

        return $tempPath;
    }

    private function createTempFilePath(string $extension): string
    {
        $basePath = tempnam(sys_get_temp_dir(), 'pdf');

        if ($basePath === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        $tempPath = $basePath . $extension;

        if (! rename($basePath, $tempPath)) {
            @unlink($basePath);
            throw new RuntimeException('Unable to prepare a temporary file.');
        }

        return $tempPath;
    }

    private function removeTempFile(?string $path): void
    {
        if (! filled($path) || ! file_exists($path)) {
            return;
        }

        @unlink($path);
    }

    private function clearSignedFile(Document $document, string $disk): void
    {
        $signedDisk = $document->signed_file_disk ?: $disk;

        if ($document->signed_file_path && Storage::disk($signedDisk)->exists($document->signed_file_path)) {
            Storage::disk($signedDisk)->delete($document->signed_file_path);
        }

        $document->forceFill([
            'signed_file_disk' => null,
            'signed_file_path' => null,
            'signed_file_generated_at' => null,
        ])->saveQuietly();
    }
}
