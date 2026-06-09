<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_upload_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('jenis', 40);
            $table->string('filename')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->foreign('batch_id', 'fk_import_log_batch')->references('id')->on('batch');
            $table->index(['batch_id', 'jenis'], 'idx_import_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_upload_logs');
    }
};
