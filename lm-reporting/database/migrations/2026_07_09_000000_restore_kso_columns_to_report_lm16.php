<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kembalikan kolom bi_kso/sd_kso di report_lm16 yang sempat dihapus manual
 * di database server (kode split Olah/KSO dipulihkan 2026-07-09).
 * Idempoten: hanya menambah bila kolom belum ada (DB lokal/test dari migrasi
 * awal sudah memilikinya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_lm16', function (Blueprint $table) {
            if (! Schema::hasColumn('report_lm16', 'bi_kso')) {
                $table->decimal('bi_kso', 20, 2)->default(0)->after('bi_olah');
            }
            if (! Schema::hasColumn('report_lm16', 'sd_kso')) {
                $table->decimal('sd_kso', 20, 2)->default(0)->after('sd_olah');
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_lm16', function (Blueprint $table) {
            if (Schema::hasColumn('report_lm16', 'bi_kso')) {
                $table->dropColumn('bi_kso');
            }
            if (Schema::hasColumn('report_lm16', 'sd_kso')) {
                $table->dropColumn('sd_kso');
            }
        });
    }
};
