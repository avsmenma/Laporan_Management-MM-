<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

        Schema::create('alokasi_produksi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->string('kebun_code', 8);
            $table->string('pabrik_code', 8)->nullable();
            $table->string('produk', 40);
            $table->decimal('jumlah', 20, 2)->default(0);
            $table->index(['batch_id', 'kebun_code', 'produk', 'month'], 'idx_alok');
            $table->foreign('batch_id', 'fk_alok_batch')->references('id')->on('batch');
        });

        Schema::create('alokasi_areal', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->string('kebun_code', 8);
            $table->decimal('real_thn_lalu', 14, 2)->default(0);
            $table->decimal('real_thn_ini', 14, 2)->default(0);
            $table->decimal('rko', 14, 2)->default(0);
            $table->decimal('rkap', 14, 2)->default(0);
            $table->unique(['year', 'kebun_code'], 'uq_areal');
        });

        Schema::create('pks_biaya', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('plant_code', 8);
            $table->tinyInteger('period');
            $table->string('cost_center', 20)->nullable();
            $table->string('cost_element', 20)->nullable();
            $table->string('klasifikasi_code', 4)->nullable();
            $table->decimal('nilai', 20, 2)->default(0);
            $table->index(['batch_id', 'plant_code', 'period', 'cost_center', 'cost_element'], 'idx_pksb');
            $table->foreign('batch_id', 'fk_pksb_batch')->references('id')->on('batch');
        });

        Schema::create('pks_produksi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('plant_code', 8);
            $table->tinyInteger('period');
            $table->string('uraian', 80);
            $table->decimal('nilai_bi', 20, 2)->default(0);
            $table->decimal('nilai_sd', 20, 2)->default(0);
            $table->index(['batch_id', 'plant_code', 'period', 'uraian'], 'idx_pksp');
            $table->foreign('batch_id', 'fk_pksp_batch')->references('id')->on('batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pks_produksi');
        Schema::dropIfExists('pks_biaya');
        Schema::dropIfExists('alokasi_areal');
        Schema::dropIfExists('alokasi_produksi');
        Schema::dropIfExists('db_pks');
        Schema::dropIfExists('db_btl');
        Schema::dropIfExists('db_wbs');
    }
};
