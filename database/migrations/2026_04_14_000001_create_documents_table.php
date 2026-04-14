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
            $table->string('document_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_type')->nullable();
            $table->longText('file_data')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->enum('storage_mode', ['base64', 'upload', 'auto'])->default('auto');
            $table->unsignedInteger('days_allowed');
            $table->timestamp('assigned_at');
            $table->timestamp('expires_at');
            $table->enum('status', ['pending', 'in-review', 'reviewed', 'signed'])->default('in-review');
            $table->boolean('review_acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->boolean('signature_invited')->default(false);
            $table->timestamp('signature_invited_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
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
