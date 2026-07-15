<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah penjualan produk dari GL SAP (sheet "Data" workbook Penjualan Produk).
 * Tiap baris = satu posting pendapatan (akun 411/420). Qty & Amount tersimpan APA ADANYA
 * (NEGATIF = kredit pendapatan; baris koreksi positif ikut) — total per material×
 * (customer|profit center)×period tervalidasi selisih 0 terhadap pivot workbook acuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penjualan_produk', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->string('document_number', 20)->nullable();
            $table->date('posting_date')->index();
            $table->smallInteger('year');
            $table->tinyInteger('period');                                 // 1..12 (Posting Period)
            $table->string('account', 20)->nullable();                     // 41100000 / 41100006 / 42000030
            $table->string('gl_account_desc', 150)->nullable();
            $table->string('profit_center', 20)->index();                  // 5F01000001 / 5E06000001 ...
            $table->string('profit_center_desc', 150)->nullable();
            $table->string('material_code', 20)->nullable();
            $table->string('material_desc', 100)->index();                 // CPO / INTI SAWIT / Lump / TBS ...
            $table->decimal('qty', 20, 2)->default(0);                     // Quantity (negatif = jual)
            $table->string('uom', 10)->nullable();
            $table->decimal('amount', 20, 2)->default(0);                  // Amount in Local Currency (negatif)
            $table->string('customer_code', 30)->nullable()->index();
            $table->string('customer_name', 200)->nullable();
            $table->string('document_type', 10)->nullable();
            $table->string('reference', 30)->nullable();
            $table->timestamps();

            $table->index(['year', 'period'], 'idx_pjl_year_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penjualan_produk');
    }
};
