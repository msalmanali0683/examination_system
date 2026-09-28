<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fingerprint of the session data a cached report was built from (see ReportFileCache::currentStamp()),
     * so a file is only served while that data is unchanged. Existing rows have none and simply count as out of
     * date, which rebuilds them once on their next download.
     */
    public function up(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->string('data_stamp', 64)->nullable()->after('filters_hash');
        });
    }

    public function down(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn('data_stamp');
        });
    }
};
