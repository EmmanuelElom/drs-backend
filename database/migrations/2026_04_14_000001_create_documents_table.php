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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_uuid')->unique('documents_document_uuid_unique');
            $table->foreignId('owner_id')->nullable()->constrained('users', indexName: 'documents_owner_id_foreign')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users', indexName: 'documents_created_by_id_foreign')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'documents_user_id_foreign')->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users', indexName: 'documents_assigned_by_id_foreign')->nullOnDelete();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_type')->nullable();
            $table->longText('file_data')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->enum('storage_mode', ['base64', 'upload', 'auto'])->default('auto');
            $table->unsignedInteger('days_allowed')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('review_acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->boolean('signature_invited')->default(false);
            $table->timestamp('signature_invited_at')->nullable();
            $table->boolean('signature_completed')->default(false);
            $table->timestamp('signature_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status'], 'documents_owner_status_index');
            $table->index(['user_id', 'status'], 'documents_user_status_index');
            $table->index(['created_by_id', 'status'], 'documents_created_by_status_index');
            $table->index('expires_at', 'documents_expires_at_index');
            $table->index('archived_at', 'documents_archived_at_index');
            $table->index('completed_at', 'documents_completed_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
