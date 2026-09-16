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
        Schema::create('report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->string('report_key');
            $table->string('filters_hash', 32);
            $table->json('filters')->nullable();
            $table->string('disk_path');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['exam_session_id', 'report_key', 'filters_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_files');
    }
};
