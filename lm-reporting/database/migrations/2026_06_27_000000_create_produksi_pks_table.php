<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi_pks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->date('posting_date')->index();
            $table->string('plant_code', 12)->nullable()->index();
            $table->string('plant_desc', 150)->nullable();
            $table->string('group_pemilik', 30)->nullable();
            $table->string('kebun_code', 20)->nullable()->index();
            $table->string('nama_kebun', 150)->nullable();
            $table->decimal('sisa_awal', 20, 2)->default(0);
            $table->decimal('tbs_diterima_sdhari', 20, 2)->default(0);
            $table->decimal('tbs_diterima_sdbulan', 20, 2)->default(0);
            $table->decimal('tbs_diolah_sdhari', 20, 2)->default(0);
            $table->decimal('tbs_diolah_sdbulan', 20, 2)->default(0);
            $table->decimal('sisa_akhir', 20, 2)->default(0);
            $table->decimal('ms_sdhari', 20, 2)->default(0);
            $table->decimal('ms_sdbulan', 20, 2)->default(0);
            $table->decimal('is_sdhari', 20, 2)->default(0);
            $table->decimal('is_sdbulan', 20, 2)->default(0);
            $table->boolean('tidak_mengolah')->default(false);
            $table->timestamps();
            $table->index(['posting_date', 'plant_code', 'kebun_code'], 'idx_produksi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_pks');
    }
};
