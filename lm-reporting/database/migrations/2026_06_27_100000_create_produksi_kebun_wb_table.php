<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah jembatan timbang TBS kebun (sheet ZESTHLE020). Tiap baris = satu
 * transaksi timbang. Kolom turunan supply/kategori/short_plant dihitung saat impor
 * (rumus di Excel rusak/#REF) lalu disimpan agar pivot di server cepat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi_kebun_wb', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->date('posting_date')->index();
            $table->string('plant_code', 12)->nullable()->index();        // pabrik penerima (5F..)
            $table->string('plant_desc', 150)->nullable();
            $table->string('goods_recipient', 20)->nullable()->index();   // kode kebun (5E..) bila Kebun Sendiri
            $table->string('desc_plant_kebun', 150)->nullable();          // UNIT KERJA
            $table->string('afdeling', 20)->nullable();
            $table->string('supplier_code', 30)->nullable()->index();
            $table->string('supplier_name', 200)->nullable();
            $table->decimal('weight_netto', 20, 2)->default(0);
            $table->string('supply', 20)->index();                        // Kebun Sendiri / Pembelian
            $table->string('kategori_pembelian', 30)->nullable();         // Kebun Pihak 3 / Kebun Plasma
            $table->string('short_plant', 20)->nullable();                // Pagun/Pakem/.. (pabrik penerima)
            $table->timestamps();

            $table->index(['posting_date', 'supply'], 'idx_pkw_period_supply');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_kebun_wb');
    }
};
