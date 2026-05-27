<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'signed_file_disk')) {
                $table->string('signed_file_disk')->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('documents', 'signed_file_path')) {
                $table->string('signed_file_path')->nullable()->after('signed_file_disk');
            }

            if (! Schema::hasColumn('documents', 'signed_file_generated_at')) {
                $table->timestamp('signed_file_generated_at')->nullable()->after('signed_file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'signed_file_generated_at')) {
                $table->dropColumn('signed_file_generated_at');
            }

            if (Schema::hasColumn('documents', 'signed_file_path')) {
                $table->dropColumn('signed_file_path');
            }

            if (Schema::hasColumn('documents', 'signed_file_disk')) {
                $table->dropColumn('signed_file_disk');
            }
        });
    }
};
