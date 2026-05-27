<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents', indexName: 'signatures_document_id_foreign')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations', indexName: 'signatures_invitation_id_foreign')->nullOnDelete();
            $table->foreignId('document_field_id')->nullable()->constrained('document_fields', indexName: 'signatures_document_field_id_foreign')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'signatures_user_id_foreign')->nullOnDelete();
            $table->string('signer_name');
            $table->string('signer_email')->nullable();
            $table->longText('signature_data');
            $table->timestamp('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'user_id'], 'signatures_document_user_index');
            $table->index(['document_id', 'invitation_id'], 'signatures_document_invitation_index');
            $table->index('document_field_id', 'signatures_document_field_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
