<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_rkap', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->string('plant_code', 8);
            $table->enum('report_type', ['LM14', 'LM13', 'LM16']);
            $table->string('kode', 40);
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['year', 'komoditi', 'plant_code', 'report_type', 'kode'], 'idx_rkap');
        });

        Schema::create('budget_rko', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->string('plant_code', 8);
            $table->enum('report_type', ['LM14', 'LM13', 'LM16']);
            $table->string('kode', 40);
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['year', 'komoditi', 'plant_code', 'report_type', 'kode'], 'idx_rko');
        });

        Schema::create('realisasi_tahun_lalu', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->string('plant_code', 8);
            $table->enum('report_type', ['LM14', 'LM13', 'LM16']);
            $table->string('kode', 40);
            $table->tinyInteger('period');
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['year', 'komoditi', 'plant_code', 'report_type', 'kode', 'period'], 'idx_tl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realisasi_tahun_lalu');
        Schema::dropIfExists('budget_rko');
        Schema::dropIfExists('budget_rkap');
    }
};
