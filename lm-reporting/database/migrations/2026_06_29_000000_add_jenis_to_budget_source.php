<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Memisahkan baris sumber RKO vs RKAP (sebelumnya satu nilai dipakai keduanya).
        // 'rko' | 'rkap'. Nullable demi data lama; importer baru selalu mengisinya.
        Schema::table('budget_source', function (Blueprint $table) {
            $table->string('jenis', 8)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('budget_source', fn (Blueprint $table) => $table->dropColumn('jenis'));
    }
};
