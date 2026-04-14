<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_users_documents_and_audit_logs(): void
    {
        $admin = User::query()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        $token = $this->loginAndGetToken('admin', 'admin123');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/users', [
                'username' => 'reviewer',
                'email' => 'reviewer@example.com',
                'password' => 'reviewer123',
                'role' => 'user',
            ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'reviewer');

        $reviewer = User::query()->where('username', 'reviewer')->firstOrFail();

        $createDocument = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/documents', [
                'user_id' => $reviewer->id,
                'title' => 'Policy Review',
                'content' => 'Please review this policy.',
                'days_allowed' => 5,
            ])
            ->assertCreated()
            ->json('data');

        $documentId = $createDocument['documentId'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/documents/{$documentId}/days", [
                'days_allowed' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('data.daysAllowed', 9);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/documents/{$documentId}/invite-signature")
            ->assertOk()
            ->assertJsonPath('data.signatureInvited', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/documents/{$documentId}")
            ->assertOk()
            ->assertJsonPath('message', 'Document deleted successfully.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/users/{$reviewer->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully.');

        $this->assertDatabaseMissing('users', ['id' => $reviewer->id]);
        $this->assertDatabaseMissing('documents', ['document_uuid' => $documentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_deleted']);
    }

    public function test_user_can_review_comment_acknowledge_and_sign_assigned_document(): void
    {
        $admin = User::query()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        $user = User::query()->create([
            'username' => 'user',
            'email' => 'user@example.com',
            'password' => Hash::make('user123'),
            'role' => 'user',
        ]);

        $document = Document::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'assigned_by_id' => $admin->id,
            'title' => 'Signed Workflow',
            'content' => 'A reviewable document.',
            'days_allowed' => 7,
            'assigned_at' => now(),
            'expires_at' => now()->copy()->addDays(7),
            'status' => 'reviewed',
            'review_acknowledged' => false,
            'signature_invited' => true,
            'signature_invited_at' => now(),
        ]);

        $token = $this->loginAndGetToken('user', 'user123');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $commentResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/documents/{$document->document_uuid}/comments", [
                'selected_text' => 'reviewable section',
                'comment' => 'Looks good.',
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/documents/{$document->document_uuid}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.reviewAcknowledged', true)
            ->assertJsonPath('data.status', 'reviewed');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/documents/{$document->document_uuid}/signatures", [
                'signature_data' => 'data:image/png;base64,abc123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.signatureData', 'data:image/png;base64,abc123');

        $this->assertDatabaseHas('comments', ['id' => $commentResponse['id']]);
        $this->assertDatabaseHas('signatures', ['document_id' => $document->id]);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'signed',
            'review_acknowledged' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comment_added']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'signature_added']);
    }

    public function test_regular_user_cannot_access_admin_endpoints(): void
    {
        User::query()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        User::query()->create([
            'username' => 'user',
            'email' => 'user@example.com',
            'password' => Hash::make('user123'),
            'role' => 'user',
        ]);

        $token = $this->loginAndGetToken('user', 'user123');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/users')
            ->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/users', [
                'username' => 'not-allowed',
                'email' => 'not-allowed@example.com',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertForbidden();
    }

    public function test_users_can_update_their_profile_and_admins_can_update_users(): void
    {
        User::query()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        $user = User::query()->create([
            'username' => 'user',
            'email' => 'user@example.com',
            'password' => Hash::make('user123'),
            'role' => 'user',
        ]);

        $adminToken = $this->loginAndGetToken('admin', 'admin123');
        $userToken = $this->loginAndGetToken('user', 'user123');

        $this->withHeader('Authorization', "Bearer {$userToken}")
            ->putJson('/api/me', [
                'username' => 'user-updated',
                'email' => 'updated@example.com',
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])
            ->assertOk()
            ->assertJsonPath('user.username', 'user-updated')
            ->assertJsonPath('user.email', 'updated@example.com');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson("/api/users/{$user->id}", [
                'username' => 'reviewer-updated',
                'email' => 'reviewer-updated@example.com',
                'role' => 'user',
                'password' => 'reviewer456',
                'password_confirmation' => 'reviewer456',
            ])
            ->assertOk()
            ->assertJsonPath('data.username', 'reviewer-updated')
            ->assertJsonPath('data.email', 'reviewer-updated@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'reviewer-updated',
            'email' => 'reviewer-updated@example.com',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile_updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed']);
    }

    public function test_admin_can_change_document_storage_mode_and_upload_pdf_files(): void
    {
        User::query()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        $user = User::query()->create([
            'username' => 'user',
            'email' => 'user@example.com',
            'password' => Hash::make('user123'),
            'role' => 'user',
        ]);

        Storage::fake('local');

        $token = $this->loginAndGetToken('admin', 'admin123');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/settings/document-storage-mode')
            ->assertOk()
            ->assertJsonPath('data.storageMode', 'auto');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/settings/document-storage-mode', [
                'storage_mode' => 'upload',
            ])
            ->assertOk()
            ->assertJsonPath('data.storageMode', 'upload');

        $pdfPath = base_path('tests/Fixtures/policy-review.pdf');
        $pdf = new UploadedFile($pdfPath, 'policy-review.pdf', 'application/pdf', null, true);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/documents', [
                'user_id' => $user->id,
                'title' => 'Uploaded PDF Review',
                'content' => 'Please review the uploaded file.',
                'days_allowed' => 4,
                'file' => $pdf,
                'file_name' => $pdf->getClientOriginalName(),
                'file_type' => 'application/pdf',
                'file_size' => $pdf->getSize(),
            ])
            ->assertCreated();

        $documentId = $response->json('data.documentId');
        $document = Document::query()->where('document_uuid', $documentId)->firstOrFail();

        $this->assertSame('upload', $document->storage_mode);
        $this->assertNotNull($document->file_path);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'document_storage_mode',
            'value' => 'upload',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings_updated']);
        Storage::disk('local')->assertExists($document->file_path);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/documents/{$documentId}")
            ->assertOk()
            ->assertJsonPath('data.storageMode', 'upload')
            ->assertJsonPath('data.fileName', 'policy-review.pdf');
    }

    private function loginAndGetToken(string $username, string $password): string
    {
        return $this->postJson('/api/login', [
            'username' => $username,
            'password' => $password,
        ])->assertOk()->json('token');
    }
}
