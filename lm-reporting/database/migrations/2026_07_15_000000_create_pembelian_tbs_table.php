<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah pembelian TBS dari SAP (sheet "Data" workbook Pembelian TBS).
 * Tiap baris = satu dokumen Good Receipt / Invoice. Qty & Actual Value dijumlahkan
 * apa adanya (termasuk nilai minus = koreksi) — total per plant×batch×period sudah
 * tervalidasi selisih 0 terhadap pivot workbook acuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembelian_tbs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->date('posting_date')->index();
            $table->smallInteger('year');
            $table->tinyInteger('period');                                // 1..12 (kolom Period)
            $table->string('plant_code', 12)->index();                    // pabrik pembeli (5F..)
            $table->string('plant_desc', 150)->nullable();
            $table->string('batch', 12)->index();                         // PHTG (Pihak 3) / PLSM (Plasma)
            $table->string('vendor_code', 30)->nullable()->index();
            $table->string('vendor_name', 200)->nullable();
            $table->string('uom', 10)->nullable();                        // KG
            $table->decimal('qty', 20, 2)->default(0);                    // Qty TBS
            $table->decimal('prelim_val', 20, 2)->default(0);             // Prelim Val
            $table->decimal('price_diff', 20, 2)->default(0);             // Price Diff
            $table->decimal('actual_value', 20, 2)->default(0);           // Actual Value
            $table->decimal('price', 18, 2)->default(0);                  // Price (Rp/Kg dokumen)
            $table->string('jenis', 30)->nullable();                      // Good Receipt / Invoice
            $table->string('contract', 30)->nullable();
            $table->string('purch_order', 30)->nullable();
            $table->string('mat_doc', 30)->nullable();
            $table->timestamps();

            $table->index(['year', 'period'], 'idx_ptbs_year_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembelian_tbs');
    }
};
