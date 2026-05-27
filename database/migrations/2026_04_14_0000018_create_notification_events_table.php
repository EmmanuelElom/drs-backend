<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);
            $table->string('action')->nullable();
            $table->string('channel')->default('mail');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'notification_events_user_id_foreign')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents', indexName: 'notification_events_document_id_foreign')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations', indexName: 'notification_events_invitation_id_foreign')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 50)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status'], 'notification_events_event_type_status_index');
            $table->index(['recipient_email', 'sent_at'], 'notification_events_recipient_email_sent_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
