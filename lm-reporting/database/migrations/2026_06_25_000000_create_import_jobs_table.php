<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 20);
            $table->smallInteger('year');
            $table->tinyInteger('month')->nullable();
            $table->string('filename', 255);
            $table->string('staged_path', 255);
            $table->string('ext', 8);
            $table->enum('status', ['queued', 'processing', 'done', 'failed'])->default('queued');
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->integer('row_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
