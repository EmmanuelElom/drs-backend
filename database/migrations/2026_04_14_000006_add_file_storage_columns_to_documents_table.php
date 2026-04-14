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
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'file_disk')) {
                $table->string('file_disk')->nullable()->after('file_data');
            }

            if (! Schema::hasColumn('documents', 'file_path')) {
                $table->string('file_path')->nullable()->after('file_disk');
            }

            if (! Schema::hasColumn('documents', 'storage_mode')) {
                $table->enum('storage_mode', ['base64', 'upload', 'auto'])
                    ->default('auto')
                    ->after('file_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'storage_mode')) {
                $table->dropColumn('storage_mode');
            }

            if (Schema::hasColumn('documents', 'file_path')) {
                $table->dropColumn('file_path');
            }

            if (Schema::hasColumn('documents', 'file_disk')) {
                $table->dropColumn('file_disk');
            }
        });
    }
};
