<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents', indexName: 'document_fields_document_id_foreign')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations', indexName: 'document_fields_invitation_id_foreign')->nullOnDelete();
            $table->string('assigned_recipient_email')->nullable();
            $table->string('field_type')->default('signature');
            $table->unsignedInteger('page');
            $table->decimal('x', 10, 6);
            $table->decimal('y', 10, 6);
            $table->decimal('width', 10, 6);
            $table->decimal('height', 10, 6);
            $table->boolean('required')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'field_type'], 'document_fields_document_field_type_index');
            $table->index(['invitation_id', 'assigned_recipient_email'], 'document_fields_invitation_assigned_recipient_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_fields');
    }
};
