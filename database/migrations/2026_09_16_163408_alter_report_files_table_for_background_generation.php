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
        Schema::table('report_files', function (Blueprint $table) {
            $table->string('disk_path')->nullable()->change();
            $table->timestamp('generated_at')->nullable()->change();
            $table->string('status', 20)->default('ready')->after('filters_hash');
            $table->text('error')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn(['status', 'error']);
            $table->string('disk_path')->nullable(false)->change();
            $table->timestamp('generated_at')->nullable(false)->change();
        });
    }
};
