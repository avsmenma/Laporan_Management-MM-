<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah line-item GL SAP untuk halaman RBB (Rincian PNL) — sheet "Data" pada
 * workbook "Beban Pokok & Usaha". Satu berkas = satu bulan (±240 rb baris).
 *
 * Empat kolom klasifikasi (klasifikasi/klasifikasi_2/jenis_beban/segmen) BUKAN dari SAP:
 * di workbook itu kolom berumus VLOOKUP ke sheet "Lock". Nilainya disimpan apa adanya
 * supaya angka halaman identik dengan pivot "Report I." tanpa menebak ulang pemetaan.
 * Amount bertanda dari SAP (penjualan & pendapatan negatif) → agregasi cukup SUM(amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbb_gl', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('period');                          // 1..12 (kolom Posting Period)
            $table->string('document_number', 20)->nullable();
            $table->date('posting_date')->nullable();
            $table->string('account', 20)->nullable();
            $table->string('gl_account_desc', 150)->nullable();
            $table->string('profit_center', 20)->nullable();
            $table->string('profit_center_desc', 150)->nullable();
            $table->string('cost_center', 30)->nullable();
            $table->string('cost_element', 20)->nullable();
            $table->string('wbs_element', 40)->nullable();
            $table->string('vendor_name', 150)->nullable();
            $table->string('document_type', 10)->nullable();
            $table->string('text', 255)->nullable();
            $table->decimal('amount', 20, 2)->default(0);
            $table->string('klasifikasi', 60)->nullable();          // 1. Penjualan Produk | 2. Beban Pokok Penjualan | ...
            $table->string('klasifikasi_2', 120)->nullable();       // a. Penjualan | b. Overhead | ...
            $table->string('jenis_beban', 150)->nullable();         // Biaya Keamanan | c. Pengendalian Gulma | ...
            $table->string('segmen', 40)->nullable();               // 1. Sawit | 2. Karet | 3. Pabrik | 4. Regional
            $table->timestamps();

            $table->index(['year', 'period'], 'idx_rbb_year_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbb_gl');
    }
};
