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
            $table->string('event_type');
            $table->string('action')->nullable();
            $table->string('channel')->default('mail');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index(['recipient_email', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};

