<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_rko', function (Blueprint $table) {
            $table->string('source', 8)->nullable()->after('kode');
        });
        Schema::table('budget_rkap', function (Blueprint $table) {
            $table->string('source', 8)->nullable()->after('kode');
        });
        Schema::table('batch', function (Blueprint $table) {
            $table->boolean('needs_regenerate')->default(true)->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('budget_rko', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('budget_rkap', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('batch', fn (Blueprint $t) => $t->dropColumn('needs_regenerate'));
    }
};
