<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_lm14', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('unit_id');
            $table->enum('komoditi', ['KS', 'KR']);
            $table->unsignedBigInteger('template_id');
            $table->decimal('real_bulan_ini', 20, 2)->default(0);
            $table->decimal('real_bulan_lalu', 20, 2)->default(0);
            $table->decimal('real_tahun_lalu', 20, 2)->default(0);
            $table->decimal('rko', 20, 2)->default(0);
            $table->decimal('rkap', 20, 2)->default(0);
            $table->decimal('cap_bi_lalu', 10, 2)->default(0);
            $table->decimal('cap_bi_thnlalu', 10, 2)->default(0);
            $table->decimal('cap_bi_rko', 10, 2)->default(0);
            $table->decimal('cap_bi_rkap', 10, 2)->default(0);
            $table->decimal('real_sd_bulan_ini', 20, 2)->default(0);
            $table->decimal('real_sd_tahunlalu', 20, 2)->default(0);
            $table->decimal('rko_sd', 20, 2)->default(0);
            $table->decimal('rkap_sd', 20, 2)->default(0);
            $table->decimal('cap_sd_thnlalu', 10, 2)->default(0);
            $table->decimal('cap_sd_rko', 10, 2)->default(0);
            $table->decimal('cap_sd_rkap', 10, 2)->default(0);
            $table->index(['batch_id', 'unit_id', 'komoditi'], 'idx_r14');
            $table->foreign('batch_id', 'fk_r14_batch')->references('id')->on('batch');
            $table->foreign('unit_id', 'fk_r14_unit')->references('id')->on('ref_unit');
            $table->foreign('template_id', 'fk_r14_tpl')->references('id')->on('lm_template_row');
        });

        Schema::create('report_lm13', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('unit_id');
            $table->enum('komoditi', ['KS', 'KR']);
            $table->unsignedBigInteger('template_id');
            $table->enum('blok', ['OLAH_JUAL', 'OLAH', 'JUAL']);
            $table->decimal('bi_real_thn_lalu', 20, 2)->default(0);
            $table->decimal('bi_real_thn_ini', 20, 2)->default(0);
            $table->decimal('bi_rko_tw', 20, 2)->default(0);
            $table->decimal('bi_rkap', 20, 2)->default(0);
            $table->decimal('sd_real_thn_lalu', 20, 2)->default(0);
            $table->decimal('sd_real_thn_ini', 20, 2)->default(0);
            $table->decimal('sd_rko_tw', 20, 2)->default(0);
            $table->decimal('sd_rkap', 20, 2)->default(0);
            $table->index(['batch_id', 'unit_id', 'komoditi', 'blok'], 'idx_r13');
            $table->foreign('batch_id', 'fk_r13_batch')->references('id')->on('batch');
            $table->foreign('unit_id', 'fk_r13_unit')->references('id')->on('ref_unit');
            $table->foreign('template_id', 'fk_r13_tpl')->references('id')->on('lm_template_row');
        });

        Schema::create('report_lm16', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('template_id');
            $table->decimal('real_bln_lalu', 20, 2)->default(0);
            $table->decimal('bi_olah', 20, 2)->default(0);
            $table->decimal('bi_kso', 20, 2)->default(0);
            $table->decimal('bi_jumlah', 20, 2)->default(0);
            $table->decimal('bi_rko', 20, 2)->default(0);
            $table->decimal('bi_rkap', 20, 2)->default(0);
            $table->decimal('sd_olah', 20, 2)->default(0);
            $table->decimal('sd_kso', 20, 2)->default(0);
            $table->decimal('sd_jumlah', 20, 2)->default(0);
            $table->decimal('sd_rko', 20, 2)->default(0);
            $table->decimal('sd_rkap', 20, 2)->default(0);
            $table->decimal('cap_bi_lalu', 10, 2)->default(0);
            $table->decimal('cap_bi_rkap', 10, 2)->default(0);
            $table->decimal('cap_bi_sd', 10, 2)->default(0);
            $table->decimal('cap_sd_rkap', 10, 2)->default(0);
            $table->decimal('rp_kg_tbs', 18, 4)->default(0);
            $table->decimal('rp_kg_mi', 18, 4)->default(0);
            $table->index(['batch_id', 'unit_id'], 'idx_r16');
            $table->foreign('batch_id', 'fk_r16_batch')->references('id')->on('batch');
            $table->foreign('unit_id', 'fk_r16_unit')->references('id')->on('ref_unit');
            $table->foreign('template_id', 'fk_r16_tpl')->references('id')->on('lm_template_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_lm16');
        Schema::dropIfExists('report_lm13');
        Schema::dropIfExists('report_lm14');
    }
};
