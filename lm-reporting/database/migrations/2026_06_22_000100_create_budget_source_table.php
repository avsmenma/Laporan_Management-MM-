<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Baris mentah RKO/RKAP (per-line) sebagai sumber drill-down kolom RKO/RKAP.
        // Mirror agregat budget_rko/budget_rkap tetapi menyimpan tiap baris BKU/OHC yang
        // diterima importer, agar grand total detail = nilai sel pada laporan.
        Schema::create('budget_source', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->enum('komoditi', ['KS', 'KR'])->nullable();
            $table->string('plant_code', 8);
            $table->enum('report_type', ['LM14', 'LM13', 'LM16']);
            $table->string('kode', 40);
            $table->string('source', 8);            // BKU | OHC
            $table->tinyInteger('period')->nullable();
            $table->string('object_name', 250)->nullable();      // Job Name (BKU) / CO Object Name (OHC)
            $table->string('cost_element', 40)->nullable();
            $table->string('cost_element_desc', 250)->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->decimal('nilai', 20, 2)->default(0);
            $table->decimal('fisik', 20, 2)->nullable();
            $table->index(['year', 'komoditi', 'plant_code', 'report_type', 'kode'], 'idx_budget_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_source');
    }
};
