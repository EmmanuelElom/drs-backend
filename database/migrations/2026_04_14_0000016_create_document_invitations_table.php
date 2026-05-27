<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents', indexName: 'document_invitations_document_id_foreign')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users', indexName: 'document_invitations_invited_by_foreign')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('access_token_hash', 64)->unique('document_invitations_access_token_hash_unique');
            $table->string('invitation_type')->default('review');
            $table->string('status')->default('pending');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('recipient_order')->default(0);
            $table->boolean('can_review')->default(false);
            $table->boolean('can_comment')->default(false);
            $table->boolean('can_sign')->default(false);
            $table->longText('signature_data')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'recipient_email'], 'document_invitations_document_recipient_email_index');
            $table->index(['status', 'expires_at'], 'document_invitations_status_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_invitations');
    }
};
