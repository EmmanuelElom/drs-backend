<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents', indexName: 'document_assignments_document_id_foreign')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'document_assignments_user_id_foreign')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users', indexName: 'document_assignments_assigned_by_foreign')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('days_allowed')->nullable();
            $table->boolean('review_acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->boolean('signature_invited')->default(false);
            $table->timestamp('signature_invited_at')->nullable();
            $table->boolean('signature_completed')->default(false);
            $table->timestamp('signature_completed_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['document_id', 'user_id'], 'document_assignments_document_user_index');
            $table->index(['status', 'expires_at'], 'document_assignments_status_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_assignments');
    }
};
