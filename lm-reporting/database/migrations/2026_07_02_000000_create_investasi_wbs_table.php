<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah realisasi biaya investasi TBM (sheet "DB"), sumber laporan
 * /kebun/investasi. Satu baris = satu posting WBS/cost element per bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investasi_wbs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('komoditi', 2)->nullable()->default('KS');
            $table->string('plant_code', 10)->index();
            $table->string('kebun_name')->nullable();
            $table->string('project')->nullable();
            $table->string('fase', 40)->nullable();
            $table->smallInteger('tahun_tanam')->nullable();
            $table->string('no_asset')->nullable();
            $table->string('aktifitas', 20)->nullable();
            $table->string('wbs_desc')->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->string('cost_element', 20)->nullable();
            $table->string('cost_element_desc')->nullable();
            $table->tinyInteger('period')->nullable();
            $table->decimal('nilai', 22, 2)->default(0);
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'plant_code', 'fase', 'tahun_tanam', 'period'], 'idx_investasi_wbs');
            $table->foreign('batch_id', 'fk_investasi_wbs_batch')->references('id')->on('batch')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investasi_wbs');
    }
};
