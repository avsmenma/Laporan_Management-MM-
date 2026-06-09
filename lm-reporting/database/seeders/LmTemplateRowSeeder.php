<?php

namespace Database\Seeders;

use App\Models\LmTemplateRow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LmTemplateRowSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('lm_template_row')->truncate();
        Schema::enableForeignKeyConstraints();

        DB::unprepared(file_get_contents(database_path('seeders/sql/seed_lm_template_row.sql')));

        LmTemplateRow::query()
            ->whereIn('report_type', ['LM13', 'LM16'])
            ->where('uraian', 'like', 'Jumlah%')
            ->where('row_type', 'detail')
            ->update(['row_type' => 'subtotal']);

        LmTemplateRow::query()
            ->whereIn('report_type', ['LM13', 'LM16'])
            ->where('uraian', 'like', 'Total%')
            ->update(['row_type' => 'total']);

        LmTemplateRow::query()
            ->where('report_type', 'LM16')
            ->where('uraian', 'Total Biaya Pabrik')
            ->update(['row_type' => 'total']);
    }
}
