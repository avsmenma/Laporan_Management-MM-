<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil agregasi rbb_gl untuk halaman RBB (pola sama report_lm13/report_lm16: tabel
 * mentah tetap utuh, angka laporan dimaterialisasi).
 *
 * Alasan: SUM+GROUP BY langsung atas ±240 rb baris rbb_gl memakan ±2 detik per muat
 * halaman, sedangkan hasilnya hanya ratusan baris per bulan. Dibangun ulang otomatis
 * setiap kali impor RBB selesai (lihat RbbPivotService & perintah rbb:pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbb_pivot', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('period');
            $table->string('klasifikasi', 60)->nullable();        // kolom blok laporan
            $table->string('klasifikasi_2', 120)->nullable();     // baris tingkat 1
            $table->string('jenis_beban', 150)->nullable();       // baris tingkat 2
            $table->string('account', 20)->nullable();            // baris tingkat 3
            $table->string('gl_account_desc', 150)->nullable();
            $table->string('segmen', 40)->nullable();             // sub-kolom blok
            $table->decimal('total', 20, 2)->default(0);

            $table->index(['year', 'period'], 'idx_rbb_pivot_year_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbb_pivot');
    }
};
