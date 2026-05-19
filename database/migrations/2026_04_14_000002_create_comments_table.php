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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('document_invitations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->longText('selected_text')->nullable();
            $table->longText('comment');
            $table->foreignId('parent_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->unsignedInteger('page')->nullable();
            $table->json('annotation_metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'user_id']);
            $table->index(['document_id', 'invitation_id']);
            $table->index('parent_comment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
