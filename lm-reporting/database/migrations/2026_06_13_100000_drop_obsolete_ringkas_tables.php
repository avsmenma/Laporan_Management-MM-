<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus tabel ringkas lama yang sudah TIDAK dipakai setelah migrasi sumber LM14/LM13
 * ke tabel mentah SAP: db_wbs & db_btl digantikan db_wbs_raw & db_ohc; db_pks tidak
 * pernah dipakai mesin laporan (PKS pakai pks_biaya/pks_produksi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('db_wbs');
        Schema::dropIfExists('db_btl');
        Schema::dropIfExists('db_pks');
    }

    public function down(): void
    {
        Schema::create('db_wbs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->enum('komoditi', ['KS', 'KR']);
            $table->string('plant_code', 8);
            $table->tinyInteger('period');
            $table->string('aktivitas', 20)->nullable();
            $table->string('job_name', 150)->nullable();
            $table->string('cost_element', 20)->nullable();
            $table->string('cost_element_desc', 150)->nullable();
            $table->string('klasifikasi_code', 4)->nullable();
            $table->decimal('nilai', 20, 2)->default(0);
            $table->decimal('fisik', 20, 2)->nullable();
            $table->index(['batch_id', 'komoditi', 'plant_code', 'period', 'aktivitas'], 'idx_wbs');
            $table->foreign('batch_id', 'fk_wbs_batch')->references('id')->on('batch');
        });

        Schema::create('db_btl', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->enum('komoditi', ['KS', 'KR']);
            $table->string('plant_code', 8);
            $table->string('unit_kerja', 100)->nullable();
            $table->tinyInteger('period');
            $table->string('kode_cc', 20)->nullable();
            $table->string('co_object_name', 150)->nullable();
            $table->string('cost_element', 20)->nullable();
            $table->string('cost_element_name', 150)->nullable();
            $table->string('klasifikasi_code', 4)->nullable();
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['batch_id', 'komoditi', 'plant_code', 'period', 'kode_cc'], 'idx_btl');
            $table->foreign('batch_id', 'fk_btl_batch')->references('id')->on('batch');
        });

        Schema::create('db_pks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('plant_code', 8);
            $table->tinyInteger('period');
            $table->string('kode_akun', 20)->nullable();
            $table->string('uraian', 150)->nullable();
            $table->enum('jenis', ['produksi', 'biaya']);
            $table->enum('olah_kso', ['Olah', 'KSO'])->nullable();
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['batch_id', 'plant_code', 'period', 'kode_akun'], 'idx_pks');
            $table->foreign('batch_id', 'fk_pks_batch')->references('id')->on('batch');
        });
    }
};
