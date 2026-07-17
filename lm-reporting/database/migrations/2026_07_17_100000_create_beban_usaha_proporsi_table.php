<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel proporsi ABS Sawit/Karet halaman Beban Administrasi (tab PROPORSI).
 * Diisi manual oleh Operator/Admin lewat inline edit tabel — meniru konstanta
 * "ABS Sawit"/"ABS Karet" yang di workbook acuan diketik manual tiap bulan.
 * %Proporsi tidak disimpan (dihitung = nilai_proporsi / total_nilai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beban_usaha_proporsi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('month');                       // 1..12
            $table->string('uraian', 20);                       // 'ABS Sawit' | 'ABS Karet'
            $table->decimal('total_nilai', 20, 2)->default(0);
            $table->decimal('nilai_proporsi', 20, 2)->default(0);
            $table->timestamps();

            $table->unique(['year', 'month', 'uraian'], 'uq_bup_year_month_uraian');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beban_usaha_proporsi');
    }
};
