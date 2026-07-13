<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel materialisasi "PRODUKSI CPO + INTI" (Alokasi Biaya Olah — Pabrik).
 *
 * Matriks Kebun (baris) × PKS/Pabrik (kolom). Nilai per sel:
 *   produksi CPO + Inti = produksi Minyak Sawit + produksi Inti Sawit.
 * Sumber: produksi_pks (ms_* + is_*), snapshot posting_date TERBARU per (tahun, bulan).
 * Dua blok mengikuti konvensi laporan: "Bulan Ini" (s/d hari) & "S.D Bulan Ini".
 *
 * Diregenerasi otomatis saat data produksi berubah (impor produksi PKS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi_cpo_inti', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year')->unsigned()->index();
            $table->tinyInteger('month')->unsigned();
            $table->date('posting_date')->comment('Snapshot posting_date sumber (terbaru dlm bulan).');
            $table->string('kebun_code', 20)->index();
            $table->string('nama_kebun', 150)->nullable();
            $table->string('plant_code', 12)->index();
            $table->string('plant_short', 30)->nullable()->comment('Nama singkat PKS (Pagun, Parba, ...).');

            // Blok "Bulan Ini" (s/d hari): Minyak Sawit + Inti Sawit.
            $table->decimal('ms_bulan_ini', 20, 2)->default(0);
            $table->decimal('is_bulan_ini', 20, 2)->default(0);
            $table->decimal('produksi_bulan_ini', 20, 2)->default(0)->comment('= ms_bulan_ini + is_bulan_ini');

            // Blok "S.D Bulan Ini": Minyak Sawit + Inti Sawit.
            $table->decimal('ms_sd', 20, 2)->default(0);
            $table->decimal('is_sd', 20, 2)->default(0);
            $table->decimal('produksi_sd', 20, 2)->default(0)->comment('= ms_sd + is_sd');

            $table->timestamps();

            $table->unique(['year', 'month', 'kebun_code', 'plant_code'], 'uniq_cpo_inti_period_cell');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_cpo_inti');
    }
};
