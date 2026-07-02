<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah mutasi aset TBM (sheet "WS"), sumber laporan /kebun/investasi.
 * Satu baris = satu aset/no per bulan dengan mutasi APC (acquisition, retirement,
 * transfer, impairment, dst).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investasi_asset', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('komoditi', 2)->nullable()->default('KS');
            $table->string('plant_code', 10)->index();
            $table->string('kebun_name')->nullable();
            $table->smallInteger('tahun_tanam')->nullable();
            $table->string('fase', 40)->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->string('asset')->nullable();
            $table->string('description')->nullable();
            $table->string('project')->nullable();
            $table->decimal('luas_ha', 20, 2)->nullable();
            $table->decimal('pokok', 20, 2)->nullable();
            $table->decimal('apc_start', 22, 2)->default(0);
            $table->decimal('acquisition', 22, 2)->default(0);
            $table->decimal('retirement', 22, 2)->default(0);
            $table->decimal('transfer', 22, 2)->default(0);
            $table->decimal('current_apc', 22, 2)->default(0);
            $table->decimal('impairment', 22, 2)->default(0);
            $table->decimal('reklas_debet', 22, 2)->default(0);
            $table->decimal('impair_awal', 22, 2)->default(0);
            $table->decimal('impair_pengurangan', 22, 2)->default(0);
            $table->decimal('curr_bk_val', 22, 2)->default(0);
            $table->string('dk_flag', 1)->nullable();
            $table->tinyInteger('period')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'plant_code', 'fase', 'tahun_tanam'], 'idx_investasi_asset');
            $table->foreign('batch_id', 'fk_investasi_asset_batch')->references('id')->on('batch')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investasi_asset');
    }
};
