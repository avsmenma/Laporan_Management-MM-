<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areal_blok', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('status', 20)->nullable();
            $table->string('status_blok_petak', 20)->nullable();
            $table->string('plant_code', 12)->nullable();
            $table->string('divisi', 20)->nullable();
            $table->string('kode_blok', 40)->nullable();
            $table->string('tanggal_mulai', 30)->nullable();
            $table->string('tanggal_sampai', 30)->nullable();
            $table->string('project_definition', 60)->nullable();
            $table->string('deskripsi', 150)->nullable();
            $table->decimal('luas_tanam', 16, 2)->default(0);
            $table->smallInteger('tahun_tanam')->nullable();
            $table->integer('total_pokok')->nullable();
            $table->decimal('luas_ha', 16, 2)->nullable();
            $table->integer('total_pokok_produktif')->nullable();
            $table->string('kondisi_areal', 30)->nullable();
            $table->string('jenis_tanah', 30)->nullable();
            $table->string('gis_id', 60)->nullable();
            $table->string('unit_kerja', 120)->nullable();
            $table->string('komoditi', 10)->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'komoditi', 'plant_code', 'status_blok_petak', 'divisi', 'tahun_tanam'], 'idx_areal');
            $table->foreign('batch_id')->references('id')->on('batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areal_blok');
    }
};
