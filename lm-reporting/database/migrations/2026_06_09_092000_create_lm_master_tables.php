<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_unit', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->string('code', 8);
            $table->string('name', 100);
            $table->enum('type', ['KEBUN', 'PABRIK']);
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->string('profit_center', 20)->nullable();
            $table->enum('olah_status', ['Olah', 'Non Olah'])->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['code', 'komoditi'], 'uq_unit_code');
        });

        Schema::create('ref_unit_komoditi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('unit_id');
            $table->enum('komoditi', ['KS', 'KR']);
            $table->unique(['unit_id', 'komoditi'], 'uq_uk');
            $table->foreign('unit_id', 'fk_uk_unit')->references('id')->on('ref_unit');
        });

        Schema::create('ref_klasifikasi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->string('code', 4)->primary();
            $table->string('name', 40);
        });

        Schema::create('batch', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->string('code', 20);
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->enum('status', ['draft', 'final', 'locked'])->default('draft');
            $table->dateTime('processed_at')->nullable();
            $table->unique(['year', 'month'], 'uq_batch');
        });

        Schema::create('lm_template_row', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->enum('report_type', ['LM14', 'LM13', 'LM16']);
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->integer('urutan');
            $table->string('kode', 40)->nullable();
            $table->string('uraian', 200);
            $table->enum('row_type', ['header', 'detail', 'subtotal', 'total'])->default('detail');
            $table->enum('source', ['WBS', 'BTL', 'PKS', 'CALC'])->nullable();
            $table->string('formula', 255)->nullable();
            $table->tinyInteger('indent')->nullable()->default(0);
            $table->index(['report_type', 'komoditi', 'urutan'], 'idx_tpl');
        });

        Schema::create('lm16_account_map', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->enum('match_type', ['cost_center', 'cost_element']);
            $table->string('kode', 20);
            $table->string('lm16_uraian', 120);
            $table->string('kelompok', 20)->nullable();
            $table->index(['match_type', 'kode'], 'idx_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lm16_account_map');
        Schema::dropIfExists('lm_template_row');
        Schema::dropIfExists('batch');
        Schema::dropIfExists('ref_klasifikasi');
        Schema::dropIfExists('ref_unit_komoditi');
        Schema::dropIfExists('ref_unit');
    }
};
