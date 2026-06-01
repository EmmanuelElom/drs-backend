<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'show_signatures_to_signers')) {
                $table->boolean('show_signatures_to_signers')->default(true)->after('signature_completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'show_signatures_to_signers')) {
                $table->dropColumn('show_signatures_to_signers');
            }
        });
    }
};
