<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah line-item GL SAP untuk laporan Beban Usaha:
 *  - report_type ADMIN → halaman Beban Administrasi (klasifikasi = Cost Element BPC Desc)
 *  - report_type BOL   → halaman Beban Ops Lainnya (klasifikasi = Kodering + Klasifikasi LM HO)
 * Nilai (amount) sudah bertanda dari SAP (posting key kredit tersimpan minus),
 * sehingga agregasi cukup SUM(amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beban_usaha_gl', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->string('report_type', 10)->index();                 // ADMIN | BOL
            $table->string('document_number', 20)->nullable();
            $table->date('posting_date');
            $table->smallInteger('year');
            $table->tinyInteger('period');                              // 1..12 (kolom Posting Period)
            $table->string('account', 20)->nullable();
            $table->string('gl_account_desc', 255)->nullable();
            $table->string('profit_center', 20)->index();               // 5R00../5E../5F..
            $table->string('profit_center_desc', 150)->nullable();
            $table->string('cost_center', 30)->nullable();              // akhiran KS/KR dipakai split komoditi (ADMIN)
            $table->string('cost_element', 20)->nullable();
            $table->string('text', 255)->nullable();
            $table->decimal('amount', 20, 2)->default(0);               // Amount in Local Currency (bertanda)
            $table->string('class_code', 20)->nullable();               // ADMIN: Cost Element BPC (900216xx) | BOL: Kodering (A1xx)
            $table->string('class_desc', 200)->nullable();              // ADMIN: Cost Element BPC Desc | BOL: Klasifikasi LM HO
            $table->timestamps();

            $table->index(['report_type', 'year', 'period'], 'idx_bugl_type_year_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beban_usaha_gl');
    }
};
