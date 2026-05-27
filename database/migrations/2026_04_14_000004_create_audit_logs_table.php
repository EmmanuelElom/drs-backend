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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('timestamp');
            $table->string('event_type')->nullable();
            $table->string('action');
            $table->string('performed_by');
            $table->foreignId('performed_by_id')->nullable()->constrained('users', indexName: 'audit_logs_performed_by_id_foreign')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users', indexName: 'audit_logs_actor_id_foreign')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('target_user')->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users', indexName: 'audit_logs_target_user_id_foreign')->nullOnDelete();
            $table->string('document_title')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents', indexName: 'audit_logs_document_id_foreign')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations', indexName: 'audit_logs_invitation_id_foreign')->nullOnDelete();
            $table->longText('details')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['action', 'timestamp'], 'audit_logs_action_timestamp_index');
            $table->index(['event_type', 'timestamp'], 'audit_logs_event_type_timestamp_index');
            $table->index('performed_by_id', 'audit_logs_performed_by_id_index');
            $table->index('actor_id', 'audit_logs_actor_id_index');
            $table->index('invitation_id', 'audit_logs_invitation_id_index');
            $table->index('document_id', 'audit_logs_document_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
