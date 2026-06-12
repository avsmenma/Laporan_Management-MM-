<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== DB WBS (raw, lengkap) — sumber baru menggantikan db_wbs ringkas =====
        Schema::create('db_wbs_raw', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->string('plant_code', 12)->nullable()->index();

            $table->string('company_code', 20)->nullable();
            $table->string('plant', 20)->nullable();
            $table->string('plant_desc', 150)->nullable();
            $table->string('divisi_afdeling', 30)->nullable();
            $table->string('blok', 30)->nullable();
            $table->string('status_blok', 20)->nullable();
            $table->string('tahun_tanam', 10)->nullable();
            $table->string('komoditi', 10)->nullable();
            $table->smallInteger('period')->nullable();
            $table->string('project', 60)->nullable();
            $table->string('wbs', 60)->nullable();
            $table->string('wbs_desc', 150)->nullable();
            $table->string('fase', 20)->nullable();
            $table->string('group_aktifitas', 30)->nullable();
            $table->string('group_desc', 150)->nullable();
            $table->string('aktifitas', 30)->nullable();
            $table->string('job_name', 150)->nullable();
            $table->string('hierarchy_area', 60)->nullable();
            $table->string('cost_center', 30)->nullable();
            $table->string('cc_desc', 150)->nullable();
            $table->string('partner_cctr', 30)->nullable();
            $table->string('partner_cctr_desc', 150)->nullable();
            $table->string('cost_element', 30)->nullable();
            $table->string('cost_element_desc', 150)->nullable();
            $table->decimal('value', 22, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('material', 40)->nullable();
            $table->string('mat_desc', 150)->nullable();
            $table->decimal('qty', 22, 4)->nullable();
            $table->string('uom', 20)->nullable();
            $table->string('object_num', 40)->nullable();
            $table->string('object_type', 20)->nullable();
            $table->string('profit_center', 30)->nullable();
            $table->string('value_type', 20)->nullable();
            $table->string('reference_procedure', 40)->nullable();
            $table->string('order_no', 40)->nullable();
            $table->string('order_type', 20)->nullable();
            $table->string('order_category', 20)->nullable();
            $table->string('order_desc', 150)->nullable();
            $table->decimal('hectare_planted', 16, 4)->nullable();
            $table->string('co_business_transaction', 20)->nullable();
            $table->string('mapping_cogm', 60)->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->string('kode', 40)->nullable();
            $table->string('pekerjaan_pb712_ii', 150)->nullable();
            $table->string('pekerjaan_pb7_i', 150)->nullable();
            $table->string('source', 40)->nullable();
            $table->string('keterangan', 255)->nullable();

            $table->index(['batch_id', 'komoditi', 'plant_code', 'period'], 'idx_wbs_raw');
        });

        // ===== DB GC (raw) =====
        Schema::create('db_gc', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->string('plant_code', 12)->nullable()->index();

            $table->string('cost_center', 30)->nullable();
            $table->string('co_object_name', 150)->nullable();
            $table->string('business_transaction', 20)->nullable();
            $table->string('document_number', 40)->nullable();
            $table->string('ref_document_number', 40)->nullable();
            $table->string('cost_element', 30)->nullable();
            $table->string('cost_element_name', 150)->nullable();
            $table->smallInteger('period')->nullable();
            $table->string('posting_date', 20)->nullable();
            $table->decimal('value_obj_crcy', 22, 2)->nullable();
            $table->decimal('total_quantity', 22, 4)->nullable();
            $table->string('posted_uom', 20)->nullable();
            $table->string('name', 150)->nullable();
            $table->string('user_name', 40)->nullable();
            $table->string('material', 40)->nullable();
            $table->string('material_description', 150)->nullable();
            $table->string('reference_procedure', 40)->nullable();
            $table->string('dr_cr_indicator', 5)->nullable();
            $table->string('reference_key', 60)->nullable();
            $table->string('partner_object_class', 40)->nullable();
            $table->string('object_type', 30)->nullable();
            $table->string('partner_object_name', 150)->nullable();
            $table->string('partner_object_type', 40)->nullable();
            $table->string('offsetting_account', 40)->nullable();
            $table->string('name_offsetting_account', 150)->nullable();
            $table->string('name_offsetting_account_2', 150)->nullable();
            $table->string('document_header_text', 150)->nullable();
            $table->string('partner_object', 60)->nullable();
            $table->string('partner_object_type3', 40)->nullable();
            $table->string('partner_cctr', 30)->nullable();
            $table->string('source_object', 60)->nullable();
            $table->string('source_object_name', 150)->nullable();
            $table->string('origin_obj_type', 40)->nullable();
            $table->string('source_object_type', 40)->nullable();
            $table->string('cost_element_descr', 150)->nullable();
            $table->string('plant', 20)->nullable();
            $table->string('afdeling', 30)->nullable();
            $table->string('kode', 40)->nullable();
            $table->string('pekerjaan_pb712_ii', 150)->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->string('pekerjaan_pb7_i', 150)->nullable();
            $table->string('komoditi', 10)->nullable();
            $table->string('unit_kerja', 150)->nullable();
            $table->string('gc', 60)->nullable();

            $table->index(['batch_id', 'komoditi', 'plant_code', 'period'], 'idx_gc');
        });

        // ===== DB OHC (raw) =====
        Schema::create('db_ohc', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->string('plant_code', 12)->nullable()->index();

            $table->string('cost_center', 30)->nullable();
            $table->string('co_object_name', 150)->nullable();
            $table->string('business_transaction', 20)->nullable();
            $table->string('document_number', 40)->nullable();
            $table->string('ref_document_number', 40)->nullable();
            $table->string('cost_element', 30)->nullable();
            $table->string('cost_element_name', 150)->nullable();
            $table->smallInteger('period')->nullable();
            $table->string('posting_date', 20)->nullable();
            $table->decimal('value_obj_crcy', 22, 2)->nullable();
            $table->decimal('total_quantity', 22, 4)->nullable();
            $table->string('posted_uom', 20)->nullable();
            $table->string('name', 150)->nullable();
            $table->string('user_name', 40)->nullable();
            $table->string('material', 40)->nullable();
            $table->string('material_description', 150)->nullable();
            $table->string('reference_procedure', 40)->nullable();
            $table->string('dr_cr_indicator', 5)->nullable();
            $table->string('reference_key', 60)->nullable();
            $table->string('partner_object_class', 40)->nullable();
            $table->string('object_type', 30)->nullable();
            $table->string('partner_object_name', 150)->nullable();
            $table->string('partner_object_type', 40)->nullable();
            $table->string('offsetting_account', 40)->nullable();
            $table->string('name_offsetting_account', 150)->nullable();
            $table->string('name_offsetting_account_2', 150)->nullable();
            $table->string('document_header_text', 150)->nullable();
            $table->string('partner_object', 60)->nullable();
            $table->string('partner_object_type3', 40)->nullable();
            $table->string('partner_cctr', 30)->nullable();
            $table->string('source_object', 60)->nullable();
            $table->string('source_object_name', 150)->nullable();
            $table->string('origin_obj_type', 40)->nullable();
            $table->string('source_object_type', 40)->nullable();
            $table->string('cost_element_descr', 150)->nullable();
            $table->string('plant', 20)->nullable();
            $table->string('lock', 20)->nullable();
            $table->string('kode', 40)->nullable();
            $table->string('pekerjaan_pb712_ii', 150)->nullable();
            $table->string('klasifikasi', 60)->nullable();
            $table->string('pekerjaan_pb7_i', 150)->nullable();
            $table->string('komoditi', 10)->nullable();
            $table->string('unit_kerja', 150)->nullable();
            $table->string('pekerjaan_pb712_iii', 150)->nullable();

            $table->index(['batch_id', 'komoditi', 'plant_code', 'period'], 'idx_ohc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_ohc');
        Schema::dropIfExists('db_gc');
        Schema::dropIfExists('db_wbs_raw');
    }
};
