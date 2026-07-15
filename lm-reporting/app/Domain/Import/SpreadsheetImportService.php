<?php

namespace App\Domain\Import;

use App\Domain\Import\Support\RawWorkbookImport;
use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\ImportUploadLog;
use App\Models\RefUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SpreadsheetImportService
{
    private array $knownUnitCodes = [];

    /** Urutan kolom db_wbs_raw sesuai urutan kolom file DB WBS (A..AV). */
    private const WBS_COLUMNS = [
        'company_code', 'plant', 'plant_desc', 'divisi_afdeling', 'blok', 'status_blok', 'tahun_tanam',
        'komoditi', 'period', 'project', 'wbs', 'wbs_desc', 'fase', 'group_aktifitas', 'group_desc',
        'aktifitas', 'job_name', 'hierarchy_area', 'cost_center', 'cc_desc', 'partner_cctr', 'partner_cctr_desc',
        'cost_element', 'cost_element_desc', 'value', 'currency', 'material', 'mat_desc', 'qty', 'uom',
        'object_num', 'object_type', 'profit_center', 'value_type', 'reference_procedure', 'order_no',
        'order_type', 'order_category', 'order_desc', 'hectare_planted', 'co_business_transaction',
        'mapping_cogm', 'klasifikasi', 'kode', 'pekerjaan_pb712_ii', 'pekerjaan_pb7_i', 'source', 'keterangan',
    ];

    /** Urutan kolom db_gc sesuai urutan kolom file DB CC GC (A..AR). */
    private const GC_COLUMNS = [
        'cost_center', 'co_object_name', 'business_transaction', 'document_number', 'ref_document_number',
        'cost_element', 'cost_element_name', 'period', 'posting_date', 'value_obj_crcy', 'total_quantity',
        'posted_uom', 'name', 'user_name', 'material', 'material_description', 'reference_procedure',
        'dr_cr_indicator', 'reference_key', 'partner_object_class', 'object_type', 'partner_object_name',
        'partner_object_type', 'offsetting_account', 'name_offsetting_account', 'name_offsetting_account_2',
        'document_header_text', 'partner_object', 'partner_object_type3', 'partner_cctr', 'source_object',
        'source_object_name', 'origin_obj_type', 'source_object_type', 'cost_element_descr', 'plant',
        'afdeling', 'kode', 'pekerjaan_pb712_ii', 'klasifikasi', 'pekerjaan_pb7_i', 'komoditi', 'unit_kerja', 'gc',
    ];

    /** Urutan kolom db_ohc sesuai urutan kolom file DB OHC (A..AR). */
    private const OHC_COLUMNS = [
        'cost_center', 'co_object_name', 'business_transaction', 'document_number', 'ref_document_number',
        'cost_element', 'cost_element_name', 'period', 'posting_date', 'value_obj_crcy', 'total_quantity',
        'posted_uom', 'name', 'user_name', 'material', 'material_description', 'reference_procedure',
        'dr_cr_indicator', 'reference_key', 'partner_object_class', 'object_type', 'partner_object_name',
        'partner_object_type', 'offsetting_account', 'name_offsetting_account', 'name_offsetting_account_2',
        'document_header_text', 'partner_object', 'partner_object_type3', 'partner_cctr', 'source_object',
        'source_object_name', 'origin_obj_type', 'source_object_type', 'cost_element_descr', 'plant',
        'lock', 'kode', 'pekerjaan_pb712_ii', 'klasifikasi', 'pekerjaan_pb7_i', 'komoditi', 'unit_kerja',
        'pekerjaan_pb712_iii',
    ];

    private const NUMERIC_RAW_COLUMNS = [
        'value' => true, 'qty' => true, 'period' => true, 'hectare_planted' => true,
        'value_obj_crcy' => true, 'total_quantity' => true,
    ];

    /** Indeks kolom 0-based sheet DB Areal. */
    private const AREAL_COLUMNS = [
        0 => 'status', 1 => 'status_blok_petak', 2 => 'plant_code', 3 => 'divisi',
        4 => 'kode_blok', 5 => 'tanggal_mulai', 6 => 'tanggal_sampai', 7 => 'project_definition',
        8 => 'deskripsi', 9 => 'luas_tanam', 10 => 'tahun_tanam', 11 => 'total_pokok',
        12 => 'luas_ha', 13 => 'total_pokok_produktif', 14 => 'kondisi_areal', 15 => 'jenis_tanah',
        16 => 'gis_id', 17 => 'unit_kerja', 18 => 'komoditi',
    ];

    /** Indeks kolom 0-based sheet DB investasi (biaya TBM → investasi_wbs). */
    private const INVESTASI_WBS_COLUMNS = [
        0 => 'plant_code', 1 => 'kebun_name', 2 => 'project', 3 => 'fase',
        4 => 'tahun_tanam', 5 => 'no_asset', 6 => 'aktifitas', 7 => 'wbs_desc',
        8 => 'klasifikasi', 9 => 'cost_element', 10 => 'cost_element_desc',
        11 => 'period', 12 => 'nilai',
    ];

    /**
     * Indeks kolom 0-based sheet WS investasi (mutasi aset TBM → investasi_asset).
     * Indeks 16, 18, 19, 21, 22 adalah sub-kolom impairment tanpa judul → dilewati.
     */
    private const INVESTASI_ASSET_COLUMNS = [
        0 => 'plant_code', 1 => 'kebun_name', 2 => 'tahun_tanam', 3 => 'fase',
        4 => 'klasifikasi', 5 => 'asset', 6 => 'description', 7 => 'project',
        8 => 'luas_ha', 9 => 'pokok', 10 => 'apc_start', 11 => 'acquisition',
        12 => 'retirement', 13 => 'transfer', 14 => 'current_apc', 15 => 'impairment',
        17 => 'reklas_debet', 20 => 'impair_awal', 23 => 'impair_pengurangan',
        24 => 'curr_bk_val', 25 => 'dk_flag',
    ];

    /** Batas panjang kolom teks investasi yang lebih pendek dari 255 (cegah error strict-mode). */
    private const INVESTASI_TEXT_LEN = [
        'fase' => 40, 'aktifitas' => 20, 'cost_element' => 20, 'klasifikasi' => 60,
    ];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'wbs' => 'DB WBS',
            'ohc' => 'DB OHC',
            'gc' => 'DB GC',
            'rko_bku' => 'RKO — BKU',
            'rko_ohc' => 'RKO — OHC',
            'rko_gc' => 'RKO — GC',
            'rko_pks_biaya' => 'RKO — Biaya PKS',
            'rko_pks_produksi' => 'RKO — Produksi PKS',
            'rkap_bku' => 'RKAP — BKU',
            'rkap_ohc' => 'RKAP — OHC',
            'rkap_gc' => 'RKAP — GC',
            'rkap_pks_biaya' => 'RKAP — Biaya PKS',
            'rkap_pks_produksi' => 'RKAP — Produksi PKS',
            'areal' => 'Areal',
            'produksi' => 'Produksi',
            'produksi_kebun' => 'Produksi Kebun',
            'pembelian_tbs' => 'Pembelian TBS',
            'penjualan_produk' => 'Penjualan Produk',
            'pks_biaya' => 'Biaya PKS (LM16)',
            'investasi_wbs' => 'Investasi — Biaya (DB)',
            'investasi_asset' => 'Investasi — Aset (WS)',
        ];
    }

    /** Jenis realisasi (per bulan) vs anggaran (per tahun). RKO & RKAP keduanya anggaran. */
    public static function isBudget(string $type): bool
    {
        return str_starts_with($type, 'rko_') || str_starts_with($type, 'rkap_');
    }

    /** Jenis anggaran: 'rko' atau 'rkap' (tabel tujuan), atau null bila bukan anggaran. */
    public static function budgetKind(string $type): ?string
    {
        if (str_starts_with($type, 'rkap_')) {
            return 'rkap';
        }
        if (str_starts_with($type, 'rko_')) {
            return 'rko';
        }

        return null;
    }

    /** Jenis produksi PKS (snapshot harian, tanpa batch). */
    public static function isProduksi(string $type): bool
    {
        return $type === 'produksi';
    }

    /** Jenis produksi Kebun (jembatan timbang TBS, sheet ZESTHLE020). */
    public static function isProduksiKebun(string $type): bool
    {
        return $type === 'produksi_kebun';
    }

    /** Jenis pembelian TBS pabrik (ekspor SAP, sheet "Data"). */
    public static function isPembelianTbs(string $type): bool
    {
        return $type === 'pembelian_tbs';
    }

    /** Jenis penjualan produk / Laba Rugi (ekspor GL SAP, sheet "Data"). */
    public static function isPenjualanProduk(string $type): bool
    {
        return $type === 'penjualan_produk';
    }

    /** Jenis yang memakai bulan sebagai penjaga periode (tanpa Batch). */
    public static function usesMonthGuard(string $type): bool
    {
        // Catatan pembelian_tbs & penjualan_produk: bulan wajib dipilih di UI, tetapi file
        // berisi banyak periode sekaligus — importer memakai TAHUN sebagai penjaga &
        // mengimpor semua periode pada tahun itu (hapus-ganti per year+period).
        return self::isBudget($type) || self::isProduksi($type) || self::isProduksiKebun($type)
            || self::isPembelianTbs($type) || self::isPenjualanProduk($type);
    }

    /** Sumber budget (BKU/OHC/GC/PKS) untuk jenis anggaran rko & rkap, atau null. */
    public static function budgetSource(string $type): ?string
    {
        return match ($type) {
            'rko_bku', 'rkap_bku' => 'BKU',
            'rko_ohc', 'rkap_ohc' => 'OHC',
            'rko_gc', 'rkap_gc' => 'GC',
            'rko_pks_biaya', 'rkap_pks_biaya' => 'PKSBIAYA',
            'rko_pks_produksi', 'rkap_pks_produksi' => 'PKSPROD',
            default => null,
        };
    }

    /** Jenis anggaran PABRIK (LM16): biaya PKS & produksi PKS. */
    public static function isBudgetPks(string $type): bool
    {
        return in_array($type, ['rko_pks_biaya', 'rkap_pks_biaya', 'rko_pks_produksi', 'rkap_pks_produksi'], true);
    }

    /**
     * Bulan distinct (1..12) dari kolom periode file realisasi. Dipakai untuk
     * mengisi dropdown bulan otomatis (asumsi domain: 1 file = 1 bulan).
     *
     * @return array<int, int>
     */
    public function detectPeriods(string $path, string $type): array
    {
        $periodIndex = match ($type) {
            'wbs' => array_search('period', self::WBS_COLUMNS, true),
            'ohc' => array_search('period', self::OHC_COLUMNS, true),
            'gc' => array_search('period', self::GC_COLUMNS, true),
            default => false,
        };
        if ($periodIndex === false) {
            return [];
        }

        $found = [];
        foreach ($this->dataRows($path) as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $raw = $values[$periodIndex] ?? null;
            if (is_numeric($raw)) {
                $m = (int) $raw;
                if ($m >= 1 && $m <= 12) {
                    $found[$m] = true;
                }
            }
        }
        ksort($found);

        return array_keys($found);
    }

    public function import(string $type, Batch $batch, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null): ImportResult
    {
        abort_unless(array_key_exists($type, self::types()), 422, 'Jenis import tidak dikenal.');
        abort_if(self::isBudget($type), 422, 'Gunakan importBudget() untuk jenis anggaran.');

        $this->knownUnitCodes = RefUnit::query()->pluck('type', 'code')->all();
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        // Baris dibaca STREAMING (memori konstan) lalu disisipkan per-chunk. File mentah
        // SAP berukuran besar (puluhan MB), jadi tidak boleh dimuat penuh ke memori.
        $result = DB::transaction(fn () => match ($type) {
            'wbs' => $this->importRaw($batch, 'db_wbs_raw', self::WBS_COLUMNS, $this->dataRows($path), 'wbs', $onProgress),
            'ohc' => $this->importRaw($batch, 'db_ohc', self::OHC_COLUMNS, $this->dataRows($path), 'gcohc', $onProgress),
            'gc' => $this->importRaw($batch, 'db_gc', self::GC_COLUMNS, $this->dataRows($path), 'gcohc', $onProgress),
            'areal' => $this->importAreal($batch, $this->dataRowsSheet($path, 'DB'), $onProgress),
            'pks_biaya' => $this->importPksBiayaFlat($batch, $this->dataRows($path), $onProgress),
            'investasi_wbs' => $this->importInvestasiWbs($batch, $this->dataRowsSheet($path, 'DB'), $onProgress),
            'investasi_asset' => $this->importInvestasiAsset($batch, $this->dataRowsSheet($path, 'WS'), $onProgress),
        });

        ImportUploadLog::query()->create([
            'batch_id' => $batch->id,
            'user_id' => $userId,
            'jenis' => $type,
            'filename' => $filename,
            'row_count' => $result->rowCount,
            'error_count' => $result->errorCount(),
            'errors' => $result->errors,
            'uploaded_at' => now(),
        ]);

        return $result;
    }

    /**
     * Impor satu file anggaran (BKU/OHC/GC) ke budget_rko + budget_rkap + budget_source.
     * Idempoten per (year, report_type=LM14, source).
     *
     * Untuk source GC: baris ditulis HANYA ke budget_source (audit), TANPA pengecekan
     * tipe unit (kebun/pabrik) dan TANPA dipetakan ke budget_rko/rkap — sebab kode GC
     * (AP/AR) tak punya baris template LM14. Ini audit-only by design.
     */
    public function importBudget(int $year, string $type, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null, ?int $month = null): ImportResult
    {
        abort_unless(self::isBudget($type), 422, 'Jenis bukan anggaran.');
        if (self::isBudgetPks($type)) {
            return $this->importBudgetPks($year, $type, $file, $userId, $onProgress, $month);
        }
        $source = self::budgetSource($type); // BKU/OHC/GC
        $kind = self::budgetKind($type);     // rko | rkap
        $targetTable = $kind === 'rkap' ? 'budget_rkap' : 'budget_rko';
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        $unitType = RefUnit::query()->get(['code', 'type'])
            ->mapWithKeys(fn ($u) => [strtoupper((string) $u->code) => $u->type])->all();
        $lm14 = DB::table('lm_template_row')
            ->where('report_type', 'LM14')->whereNotNull('kode')->where('kode', '<>', '')
            ->get(['komoditi', 'kode'])
            ->mapWithKeys(fn ($r) => [strtoupper((string) $r->komoditi).'|'.$r->kode => true])->all();

        // Indeks kolom 0-based: A=komoditi(0) B=plant(1) D=period(3) E=kode(4)
        // F=obj(5) G=ce(6) H=cedesc(7) I=klas(8) J=nilai(9) K=fisik(10)
        [$C_KOM, $C_PLANT, $C_PERIOD, $C_KODE, $C_OBJ, $C_CE, $C_CEDESC, $C_KLAS, $C_NILAI, $C_FISIK]
            = [0, 1, 3, 4, 5, 6, 7, 8, 9, 10];

        $str = fn ($v, int $len): ?string => ($t = trim((string) ($v ?? ''))) === '' ? null : mb_substr($t, 0, $len);
        $acc = [];      // "KOM|PLANT|period|kode" => nilai
        $rawSrc = [];
        $errors = [];
        $seen = 0;

        foreach ($this->dataRows($path) as $c) {
            if ($this->isEmptyRow($c)) {
                continue;
            }
            $kom = strtoupper(trim((string) ($c[$C_KOM] ?? '')));
            $plant = strtoupper(trim((string) ($c[$C_PLANT] ?? '')));
            $kode = trim((string) ($c[$C_KODE] ?? ''));
            if ($kom === '' || $plant === '' || $kode === '') {
                continue;
            }
            $seen++;
            if ($onProgress !== null && $seen % 500 === 0) {
                $onProgress($seen);
            }
            $nilai = $this->numericValue($c[$C_NILAI] ?? 0);
            $period = is_numeric($c[$C_PERIOD] ?? null) ? (int) $c[$C_PERIOD] : null;

            // Penjaga bulan: bila operator memilih bulan, hanya baris dengan period
            // yang sama yang diimpor (tidak menyentuh period lain di tahun yang sama).
            if ($month !== null && $period !== $month) {
                continue;
            }

            // GC: audit-only — hanya ke budget_source, tanpa cek tipe unit & tanpa
            // pemetaan ke budget_rko/rkap (tak ada baris template LM14 untuk kode AP/AR).
            $mappable = $source !== 'GC';
            if ($mappable) {
                if (($unitType[$plant] ?? null) !== 'KEBUN') {
                    $errors[] = "Unit non-kebun dilewati: {$plant}";

                    continue;
                }
                if (! isset($lm14[$kom.'|'.$kode])) {
                    $errors[] = "Kode di luar LM14: {$kom}/{$kode}";

                    continue;
                }
                $k = $kom.'|'.$plant.'|'.($period ?? '').'|'.$kode;
                $acc[$k] = ($acc[$k] ?? 0) + $nilai;
            }

            $rawSrc[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => $source, 'jenis' => $kind, 'period' => $period,
                'object_name' => $str($c[$C_OBJ] ?? null, 250),
                'cost_element' => $str($c[$C_CE] ?? null, 40),
                'cost_element_desc' => $str($c[$C_CEDESC] ?? null, 250),
                'klasifikasi' => $str($c[$C_KLAS] ?? null, 60),
                'nilai' => round($nilai, 2),
                'fisik' => is_numeric($c[$C_FISIK] ?? null) ? (float) $c[$C_FISIK] : null,
            ];
        }

        $rows = [];
        foreach ($acc as $key => $nilai) {
            [$kom, $plant, $period, $kode] = explode('|', $key, 4);
            $rows[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => $source,
                'period' => $period === '' ? null : (int) $period,
                'nilai' => round($nilai, 2),
            ];
        }

        DB::transaction(function () use ($rows, $rawSrc, $year, $source, $kind, $targetTable, $month): void {
            // Idempoten per-(sumber, jenis): RKO & RKAP tabel terpisah → impor RKAP tidak
            // menyentuh RKO (dan sebaliknya). Bila bulan dipilih, hapus HANYA period itu
            // agar 11 bulan lain di tahun yang sama tetap utuh.
            $scope = function ($q) use ($year, $source, $month) {
                $q->where('year', $year)->where('report_type', 'LM14')->where('source', $source);
                if ($month !== null) {
                    $q->where('period', $month);
                }

                return $q;
            };
            // Tabel anggaran tujuan (budget_rko ATAU budget_rkap) — hanya satu yang disentuh.
            $scope(DB::table($targetTable))->delete();
            // budget_source (audit/drill-down) dipisah per jenis agar rincian RKO ≠ RKAP.
            $scope(DB::table('budget_source'))->where('jenis', $kind)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($targetTable)->insert($chunk);
            }
            foreach (array_chunk($rawSrc, 500) as $chunk) {
                DB::table('budget_source')->insert($chunk);
            }
        });

        if ($onProgress !== null) {
            $onProgress($seen);
        }

        $result = new ImportResult(rowCount: count($rows), errors: array_slice($errors, 0, 50));

        ImportUploadLog::query()->create([
            'batch_id' => null,
            'user_id' => $userId,
            'jenis' => $type,
            'filename' => $filename,
            'row_count' => $result->rowCount,
            'error_count' => $result->errorCount(),
            'errors' => $result->errors,
            'uploaded_at' => now(),
        ]);

        return $result;
    }

    /**
     * Impor anggaran PABRIK (LM16) — Biaya PKS & Produksi PKS — ke budget_rko/budget_rkap
     * + budget_source. Layout kolom sama dgn anggaran OHC: A=Komoditi B=Plant D=Period
     * E=Kode CC F=CO Object Name J=Nilai.
     *
     * Pemetaan kode file → baris template LM16 (kode disimpan sbg 'U{urutan}', kunci
     * unik per baris — lihat Lm16Service::budgetCodes):
     *  - Biaya, kode berawalan '6' (600.00/603-604.xx) → seksi PENGOLAHAN (urutan 16-31),
     *    dicocokkan via nama CO Object = uraian template (spasi dinormalkan).
     *  - Biaya, kode angka lain (400..426, 490) → seksi OVERHEAD/Depresiasi (34-55),
     *    dicocokkan via bagian bulat kode template ('400.0' → 400).
     *  - Produksi: kode wajib TBS Diolah / CPO / Inti (dipakai langsung; cocok dgn
     *    budgetCodes baris produksi & rendemenBudget).
     * Kode di luar pemetaan dicatat sebagai error dan dilewati (bukan dipaksakan).
     * Idempoten per (year, report_type=LM16, source, [period bila bulan dipilih]).
     */
    private function importBudgetPks(int $year, string $type, UploadedFile|string $file, ?int $userId, ?callable $onProgress, ?int $month): ImportResult
    {
        $source = self::budgetSource($type);       // PKSBIAYA | PKSPROD
        $kind = self::budgetKind($type);           // rko | rkap
        $isProduksi = $source === 'PKSPROD';
        $targetTable = $kind === 'rkap' ? 'budget_rkap' : 'budget_rko';
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        $unitType = RefUnit::query()->get(['code', 'type'])
            ->mapWithKeys(fn ($u) => [strtoupper((string) $u->code) => $u->type])->all();

        // Peta pencocokan baris template LM16 (biaya): uraian ternormalisasi (pengolahan)
        // dan bagian bulat kode (overhead/depresiasi) → urutan baris.
        $norm = fn (string $s): string => strtolower(preg_replace('/\s+/', ' ', trim($s)) ?? '');
        $byUraian = [];
        $byKodeInt = [];
        foreach (DB::table('lm_template_row')->where('report_type', 'LM16')->whereIn('row_type', ['detail'])->get(['urutan', 'kode', 'uraian']) as $t) {
            $u = (int) $t->urutan;
            if ($u >= 16 && $u <= 31) {
                $byUraian[$norm((string) $t->uraian)] = $u;
            } elseif ($u >= 34 && $u <= 55 && is_numeric((string) $t->kode)) {
                $byKodeInt[(int) $t->kode] = $u;
            }
        }
        $produksiKode = ['TBS DIOLAH' => 'TBS Diolah', 'CPO' => 'CPO', 'INTI' => 'Inti', 'INTI SAWIT' => 'Inti'];

        // Indeks kolom 0-based (identik layout anggaran OHC).
        [$C_KOM, $C_PLANT, $C_PERIOD, $C_KODE, $C_OBJ, $C_CE, $C_CEDESC, $C_KLAS, $C_NILAI, $C_FISIK]
            = [0, 1, 3, 4, 5, 6, 7, 8, 9, 10];
        $str = fn ($v, int $len): ?string => ($t = trim((string) ($v ?? ''))) === '' ? null : mb_substr($t, 0, $len);

        $acc = [];      // "KOM|PLANT|period|kode" => nilai
        $rawSrc = [];
        $errors = [];
        $seen = 0;

        foreach ($this->dataRows($path) as $c) {
            if ($this->isEmptyRow($c)) {
                continue;
            }
            $kom = strtoupper(trim((string) ($c[$C_KOM] ?? '')));
            $plant = strtoupper(trim((string) ($c[$C_PLANT] ?? '')));
            $kodeRaw = trim((string) ($c[$C_KODE] ?? ''));
            $obj = trim((string) ($c[$C_OBJ] ?? ''));
            if ($kom === '' || $plant === '' || $kodeRaw === '') {
                continue;
            }
            $seen++;
            if ($onProgress !== null && $seen % 500 === 0) {
                $onProgress($seen);
            }
            $period = is_numeric($c[$C_PERIOD] ?? null) ? (int) $c[$C_PERIOD] : null;
            if ($month !== null && $period !== $month) {
                continue;
            }
            if (($unitType[$plant] ?? null) !== 'PABRIK') {
                $errors[] = "Unit non-pabrik dilewati: {$plant}";

                continue;
            }

            // Petakan ke kode simpan.
            if ($isProduksi) {
                $kode = $produksiKode[strtoupper($kodeRaw)] ?? null;
                if ($kode === null) {
                    $errors[] = "Kode produksi di luar LM16: {$kodeRaw}";

                    continue;
                }
            } else {
                $urutan = str_starts_with($kodeRaw, '6')
                    ? ($byUraian[$norm($obj)] ?? null)
                    : (is_numeric($kodeRaw) ? ($byKodeInt[(int) $kodeRaw] ?? null) : null);
                if ($urutan === null) {
                    $errors[] = "Kode di luar LM16: {$kodeRaw} ({$obj})";

                    continue;
                }
                $kode = 'U'.$urutan;
            }

            $nilai = $this->numericValue($c[$C_NILAI] ?? 0);
            $k = $kom.'|'.$plant.'|'.($period ?? '').'|'.$kode;
            $acc[$k] = ($acc[$k] ?? 0) + $nilai;

            $rawSrc[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM16', 'kode' => $kode, 'source' => $source, 'jenis' => $kind, 'period' => $period,
                'object_name' => $str($obj, 250),
                'cost_element' => $str($c[$C_CE] ?? null, 40),
                'cost_element_desc' => $str($c[$C_CEDESC] ?? null, 250),
                'klasifikasi' => $str($c[$C_KLAS] ?? null, 60),
                'nilai' => round($nilai, 2),
                'fisik' => is_numeric($c[$C_FISIK] ?? null) ? (float) $c[$C_FISIK] : null,
            ];
        }

        $rows = [];
        foreach ($acc as $key => $nilai) {
            [$kom, $plant, $period, $kode] = explode('|', $key, 4);
            $rows[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM16', 'kode' => $kode, 'source' => $source,
                'period' => $period === '' ? null : (int) $period,
                'nilai' => round($nilai, 2),
            ];
        }

        DB::transaction(function () use ($rows, $rawSrc, $year, $source, $kind, $targetTable, $month): void {
            $scope = function ($q) use ($year, $source, $month) {
                $q->where('year', $year)->where('report_type', 'LM16')->where('source', $source);
                if ($month !== null) {
                    $q->where('period', $month);
                }

                return $q;
            };
            $scope(DB::table($targetTable))->delete();
            $scope(DB::table('budget_source'))->where('jenis', $kind)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($targetTable)->insert($chunk);
            }
            foreach (array_chunk($rawSrc, 500) as $chunk) {
                DB::table('budget_source')->insert($chunk);
            }
        });

        if ($onProgress !== null) {
            $onProgress($seen);
        }

        $result = new ImportResult(rowCount: count($rows), errors: array_slice($errors, 0, 50));

        ImportUploadLog::query()->create([
            'batch_id' => null,
            'user_id' => $userId,
            'jenis' => $type,
            'filename' => $filename,
            'row_count' => $result->rowCount,
            'error_count' => $result->errorCount(),
            'errors' => $result->errors,
            'uploaded_at' => now(),
        ]);

        return $result;
    }

    /**
     * Pratinjau isi file sebelum dikonfirmasi: kolom, sebagian baris contoh, dan total baris data.
     *
     * @return array{type: string, label: string, columns: array<int, string>, rows: array<int, array<int, mixed>>, total: int}
     */
    public function preview(string $type, string $path, int $sampleSize = 15): array
    {
        abort_unless(array_key_exists($type, self::types()), 422, 'Jenis import tidak dikenal.');

        if (self::isProduksi($type)) {
            $total = max(0, $this->rowCountForType('produksi', $path));
            $headers = ['Plant', 'Desc', 'Group Pemilik', 'Kebun', 'Nama Kebun', 'TBS Diterima s/d Hari', 'TBS Diterima s/d Bulan', 'TBS Diolah s/d Hari', 'TBS Diolah s/d Bulan', 'Sisa Akhir', 'Tgl Posting'];
            $idx = [1, 2, 3, 4, 5, 8, 9, 11, 12, 13, 26];
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'ZPTPNHLPP039') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = array_map(fn ($i) => $row[$i] ?? null, $idx);
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return ['type' => $type, 'label' => self::types()[$type], 'columns' => $headers, 'rows' => $rows, 'total' => $total];
        }

        // Produksi Kebun: sampel dari sheet ZESTHLE020 (kolom utama untuk verifikasi mata).
        if (self::isProduksiKebun($type)) {
            $total = max(0, $this->rowCountForType($type, $path));
            $headers = ['Plant', 'Desc Plant WB', 'Goods Recipient', 'Desc Plant Kebun', 'Afdeling', 'Supplier', 'Vendor Name', 'Weight netto', 'Posting Date'];
            $idx = [0, 1, 2, 3, 4, 5, 6, 22, 12];
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'ZESTHLE020') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = array_map(fn ($i) => $row[$i] ?? null, $idx);
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return ['type' => $type, 'label' => self::types()[$type], 'columns' => $headers, 'rows' => $rows, 'total' => $total];
        }

        // Pembelian TBS: sampel dari sheet "Data" (kolom utama untuk verifikasi mata).
        if (self::isPembelianTbs($type)) {
            $total = max(0, $this->rowCountForType($type, $path));
            $headers = ['Post. Date', 'Period', 'Plant', 'Plant Desc.', 'Batch', 'Vendor', 'Vendor Name', 'Qty TBS', 'Actual Value', 'Jenis'];
            $idx = [0, 1, 2, 3, 4, 5, 6, 8, 11, 14];
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'Data') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = array_map(fn ($i) => $row[$i] ?? null, $idx);
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return ['type' => $type, 'label' => self::types()[$type], 'columns' => $headers, 'rows' => $rows, 'total' => $total];
        }

        // Penjualan Produk: sampel dari sheet "Data" (kolom utama untuk verifikasi mata).
        if (self::isPenjualanProduk($type)) {
            $total = max(0, $this->rowCountForType($type, $path));
            $headers = ['Posting Date', 'Period', 'Account', 'Profit Center', 'Description Prctr', 'Material Description', 'Quantity', 'Amount', 'Customer', 'Customer Name'];
            $idx = [1, 2, 3, 8, 9, 25, 26, 14, 31, 32];
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'Data') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = array_map(fn ($i) => $row[$i] ?? null, $idx);
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return ['type' => $type, 'label' => self::types()[$type], 'columns' => $headers, 'rows' => $rows, 'total' => $total];
        }

        // Areal: baca header + sampel dari sheet "DB", hitung total via rowCountForType.
        if ($type === 'areal') {
            $total = max(0, $this->rowCountForType('areal', $path));
            $headers = array_values(self::AREAL_COLUMNS);
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'DB') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = $row;
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return [
                'type' => $type,
                'label' => self::types()[$type],
                'columns' => $headers,
                'rows' => $rows,
                'total' => $total,
            ];
        }

        $total = max(0, $this->totalDataRows($path));

        $headers = [];
        $rows = [];
        $index = 0;
        foreach ($this->streamRows($path, $sampleSize + 1) as $row) {
            if ($index === 0) {
                $headers = $row;
            } else {
                $rows[] = $row;
            }
            $index++;
        }

        return [
            'type' => $type,
            'label' => self::types()[$type],
            'columns' => array_map(fn ($value) => trim((string) $value), $headers),
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /** Jumlah baris data (tanpa header) — cepat (tanpa muat sel). Untuk progres import. */
    public function dataRowCount(string $path): int
    {
        return $this->totalDataRows($path);
    }

    /** Jumlah baris data sesuai jenis: areal pakai sheet "DB", produksi pakai sheet "ZPTPNHLPP039", pembelian TBS sheet "Data", lainnya sheet pertama. */
    public function rowCountForType(string $type, string $path): int
    {
        if ($type !== 'areal' && ! self::isProduksi($type) && ! self::isProduksiKebun($type)
            && ! self::isPembelianTbs($type) && ! self::isPenjualanProduk($type)) {
            return $this->totalDataRows($path);
        }
        $sheet = match (true) {
            $type === 'areal' => 'DB',
            self::isProduksiKebun($type) => 'ZESTHLE020',
            self::isPembelianTbs($type), self::isPenjualanProduk($type) => 'Data',
            default => 'ZPTPNHLPP039',
        };
        $n = 0;
        foreach ($this->dataRowsSheet($path, $sheet) as $row) {
            if (! $this->isEmptyRow($row)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Baca baris data (tanpa header) dari sheet bernama $sheetName (case-insensitive);
     * fallback ke sheet pertama bila tidak ketemu.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function dataRowsSheet(string $path, string $sheetName): \Generator
    {
        $reader = new XlsxReader(new XlsxReaderOptions);
        $reader->open($path);
        $sheet = $row = null;

        try {
            // Cari indeks sheet target (case-insensitive); catat juga sheet pertama sebagai fallback.
            $targetIndex = null;
            $firstIndex = null;
            $idx = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($firstIndex === null) {
                    $firstIndex = $idx;
                }
                if (strcasecmp((string) $sheet->getName(), $sheetName) === 0) {
                    $targetIndex = $idx;
                    break;
                }
                $idx++;
            }
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();

            // Buka ulang dan baca sheet yang tepat.
            $reader = new XlsxReader(new XlsxReaderOptions);
            $reader->open($path);
            $useIndex = $targetIndex ?? $firstIndex ?? 0;
            $current = 0;
            $isHeader = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($current !== $useIndex) {
                    $current++;

                    continue;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    if ($isHeader) {
                        $isHeader = false;

                        continue;
                    }
                    yield $this->rowToArray($row);
                }
                break;
            }
        } finally {
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();
        }
    }

    /**
     * Jumlah baris data (di luar header) tanpa memuat sel. Worksheet di dalam xlsx (zip)
     * dibaca via ZipArchive: ambil dari atribut <dimension ref="A1:..">, atau—bila tidak
     * ada—nomor baris <row> terbesar dengan membaca stream secara bertahap. Memori konstan
     * dan handle dilepas eksplisit (ZipArchive::close) sehingga tidak mengunci file di Windows.
     */
    private function totalDataRows(string $path): int
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return 0;
        }

        try {
            $entry = $this->firstWorksheetEntry($zip);
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                return 0;
            }

            // <dimension> muncul di awal sheet; cukup baca kepala file.
            $head = (string) fread($stream, 8192);
            if (preg_match('/<dimension ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $head, $matches)) {
                fclose($stream);

                return max(0, (int) $matches[1] - 1);
            }

            // Fallback: pindai nomor baris <row r="N"> terbesar secara streaming.
            $lastRow = 0;
            $buffer = $head;
            do {
                if (preg_match_all('/<row r="(\d+)"/', $buffer, $all) === 1 || $all[1] !== []) {
                    $lastRow = max($lastRow, (int) end($all[1]));
                }
                $buffer = substr($buffer, -16).(string) fread($stream, 262144);
            } while (! feof($stream));
            preg_match_all('/<row r="(\d+)"/', $buffer, $all);
            if ($all[1] !== []) {
                $lastRow = max($lastRow, (int) end($all[1]));
            }
            fclose($stream);

            return max(0, $lastRow - 1);
        } catch (\Throwable) {
            return 0;
        } finally {
            $zip->close();
        }
    }

    /**
     * Nama entri worksheet pertama di dalam xlsx; default 'xl/worksheets/sheet1.xml'.
     */
    private function firstWorksheetEntry(\ZipArchive $zip): string
    {
        $default = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($default) !== false) {
            return $default;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                return $name;
            }
        }

        return $default;
    }

    /**
     * Baca sheet pertama secara STREAMING (memori konstan) via OpenSpout dan kembalikan
     * tiap baris sebagai array posisional 0-based. Untuk sel rumus (mis. kolom
     * Plant/Kode/Klasifikasi hasil VLOOKUP) dipakai nilai TERHITUNG (cache) — sama
     * seperti getOldCalculatedValue() pada PhpSpreadsheet, tapi tanpa memuat seluruh file.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function streamRows(string $path, ?int $maxRows = null): \Generator
    {
        $reader = new XlsxReader(new XlsxReaderOptions);
        $reader->open($path);
        $sheet = $row = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $emitted = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    yield $this->rowToArray($row);

                    if ($maxRows !== null && ++$emitted >= $maxRows) {
                        return;
                    }
                }

                break; // hanya sheet pertama
            }
        } finally {
            // Di Windows, close() saja tidak melepas lock file selama masih ada objek yang
            // memegang resource reader — termasuk variabel iterator sheet/row. Musnahkan
            // semuanya secara eksplisit (unset + GC) agar handle benar-benar dilepas, mis.
            // saat iterasi dihentikan dini untuk pratinjau.
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();
        }
    }

    /**
     * Baris data saja (tanpa header), untuk diumpankan langsung ke importRaw secara streaming.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function dataRows(string $path): \Generator
    {
        $first = true;
        foreach ($this->streamRows($path) as $row) {
            if ($first) {
                $first = false;

                continue;
            }

            yield $row;
        }
    }

    /**
     * Ubah satu baris OpenSpout menjadi array posisional 0-based yang rapat (lubang
     * kolom kosong diisi null agar indeks tetap selaras dengan urutan kolom file).
     * Nilai rumus diambil dari cache; tanggal distringkan agar aman disimpan/ditampilkan.
     *
     * @return array<int, mixed>
     */
    private function rowToArray(Row $row): array
    {
        $cells = $row->cells;
        if ($cells === []) {
            return [];
        }

        $out = [];
        $max = max(array_keys($cells));
        for ($i = 0; $i <= $max; $i++) {
            $cell = $cells[$i] ?? null;
            if ($cell === null) {
                $out[$i] = null;

                continue;
            }

            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
            $out[$i] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $out;
    }

    /**
     * Impor sheet DB Areal → areal_blok (idempoten per batch). Luas←J(idx 9), Pokok←N(idx 13).
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importAreal(Batch $batch, iterable $rows, ?callable $onProgress = null): ImportResult
    {
        DB::table('areal_blok')->where('batch_id', $batch->id)->delete();

        $records = [];
        $inserted = 0;
        $flush = function () use (&$records, &$inserted, $onProgress): void {
            if ($records === []) {
                return;
            }
            DB::table('areal_blok')->insert($records);
            $inserted += count($records);
            $records = [];
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
        };

        foreach ($rows as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $rec = ['batch_id' => $batch->id];
            foreach (self::AREAL_COLUMNS as $idx => $col) {
                $rec[$col] = $this->arealCell($values[$idx] ?? null, $col);
            }
            $records[] = $rec;
            if (count($records) >= 500) {
                $flush();
            }
        }
        $flush();

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    private function arealCell(mixed $v, string $col): mixed
    {
        $numeric = ['luas_tanam', 'luas_ha'];
        $int = ['tahun_tanam', 'total_pokok', 'total_pokok_produktif'];
        if (in_array($col, $numeric, true)) {
            return is_numeric($v) ? (float) $v : 0;
        }
        if (in_array($col, $int, true)) {
            return is_numeric($v) ? (int) $v : null;
        }
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, 250);
    }

    /**
     * Impor sheet DB investasi → investasi_wbs (idempoten per batch). Baca posisional
     * (indeks tetap, tanpa cocok teks header). Awal data dideteksi dari kolom A berkode
     * kebun (5Exx); baris judul/subtotal/kosong dilewati.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importInvestasiWbs(Batch $batch, iterable $rows, ?callable $onProgress = null): ImportResult
    {
        DB::table('investasi_wbs')->where('batch_id', $batch->id)->delete();

        $headers = ImportTemplateService::specs()['investasi_wbs']['headers'];
        $records = [];
        $inserted = 0;
        $flush = function () use (&$records, &$inserted, $onProgress): void {
            if ($records === []) {
                return;
            }
            DB::table('investasi_wbs')->insert($records);
            $inserted += count($records);
            $records = [];
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
        };

        foreach ($rows as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            // Deteksi awal data: hanya baris berkode kebun (5Exx di kolom A). Ini melewati
            // baris judul/kosong di atas serta baris subtotal/label secara defensif.
            if (! $this->isInvestasiPlant($values[0] ?? null)) {
                continue;
            }
            $rec = ['batch_id' => $batch->id, 'komoditi' => 'KS'];
            foreach (self::INVESTASI_WBS_COLUMNS as $idx => $col) {
                $rec[$col] = $this->investasiCell($values[$idx] ?? null, $col);
            }
            $rec['raw'] = $this->investasiRaw($headers, $values);
            $records[] = $rec;
            if (count($records) >= 500) {
                $flush();
            }
        }
        $flush();

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /**
     * Impor sheet WS investasi → investasi_asset (idempoten per batch). Baca posisional
     * (indeks tetap; sebagian sub-kolom impairment dilewati). Awal data dideteksi dari
     * kolom A berkode kebun (5Exx) — menangani header di baris ke-2 + baris junk 3–5.
     * `period` diisi dari bulan batch (register aset bersifat tahunan).
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importInvestasiAsset(Batch $batch, iterable $rows, ?callable $onProgress = null): ImportResult
    {
        DB::table('investasi_asset')->where('batch_id', $batch->id)->delete();

        $headers = ImportTemplateService::specs()['investasi_asset']['headers'];
        $period = (int) $batch->month;
        $records = [];
        $inserted = 0;
        $flush = function () use (&$records, &$inserted, $onProgress): void {
            if ($records === []) {
                return;
            }
            DB::table('investasi_asset')->insert($records);
            $inserted += count($records);
            $records = [];
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
        };

        foreach ($rows as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            if (! $this->isInvestasiPlant($values[0] ?? null)) {
                continue;
            }
            $rec = ['batch_id' => $batch->id, 'komoditi' => 'KS'];
            foreach (self::INVESTASI_ASSET_COLUMNS as $idx => $col) {
                $rec[$col] = $this->investasiCell($values[$idx] ?? null, $col);
            }
            $rec['period'] = $period;
            $rec['raw'] = $this->investasiRaw($headers, $values);
            $records[] = $rec;
            if (count($records) >= 500) {
                $flush();
            }
        }
        $flush();

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /** True bila kolom A berupa kode kebun (5Exx). Penanda baris data investasi. */
    private function isInvestasiPlant(mixed $v): bool
    {
        return (bool) preg_match('/^5E\d/', strtoupper(trim((string) ($v ?? ''))));
    }

    /**
     * Konversi satu sel investasi sesuai nama kolom tujuan: nilai → float (blank/#N/A → 0),
     * tahun_tanam/period → int (blank → null), dk_flag → 'D'/'K', selain itu teks (dipangkas).
     */
    private function investasiCell(mixed $v, string $col): mixed
    {
        $floatCols = [
            'nilai', 'luas_ha', 'pokok', 'apc_start', 'acquisition', 'retirement',
            'transfer', 'current_apc', 'impairment', 'reklas_debet', 'impair_awal',
            'impair_pengurangan', 'curr_bk_val',
        ];
        if (in_array($col, $floatCols, true)) {
            return round($this->numericValue($v), 2);
        }
        if ($col === 'tahun_tanam') {
            return $this->investasiYear($v);
        }
        if ($col === 'period') {
            return is_numeric($v) ? (int) $v : null;
        }
        if ($col === 'plant_code') {
            $t = strtoupper(trim((string) ($v ?? '')));

            return $t === '' ? null : mb_substr($t, 0, 10);
        }
        if ($col === 'dk_flag') {
            $t = strtoupper(trim((string) ($v ?? '')));

            return $t === '' ? null : mb_substr($t, 0, 1);
        }
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, self::INVESTASI_TEXT_LEN[$col] ?? 250);
    }

    /** Tahun tanam sebagai int; '2026.0' → 2026, blank/non-numerik → null. */
    private function investasiYear(mixed $v): ?int
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }

        return is_numeric($v) ? (int) (float) $v : null;
    }

    /**
     * Bangun JSON `raw` = {header spec => sel} berpasangan posisional, mirip importPksBiayaFlat.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $values
     */
    private function investasiRaw(array $headers, array $values): string
    {
        $raw = [];
        foreach ($headers as $i => $label) {
            $cell = $values[$i] ?? null;
            $raw[$label] = (is_scalar($cell) || $cell === null) ? $cell : (string) $cell;
        }

        return json_encode($raw, JSON_UNESCAPED_UNICODE);
    }

    /** Indeks kolom 0-based sheet ZPTPNHLPP039 yang dipakai. */
    private const PRODUKSI_COLS = [
        'plant_code' => 1, 'plant_desc' => 2, 'group_pemilik' => 3, 'kebun_code' => 4, 'nama_kebun' => 5,
        'sisa_awal' => 6, 'tbs_diterima_sdhari' => 8, 'tbs_diterima_sdbulan' => 9,
        'tbs_diolah_sdhari' => 11, 'tbs_diolah_sdbulan' => 12, 'sisa_akhir' => 13,
        'ms_sdhari' => 15, 'ms_sdbulan' => 16, 'is_sdhari' => 18, 'is_sdbulan' => 19,
        'tgl_posting' => 26, 'tidak_mengolah' => 27,
    ];

    /**
     * Impor sheet ZPTPNHLPP039 → produksi_pks (idempoten per posting_date). Tanggal
     * diturunkan dari kolom "Tgl Posting" (serial Excel atau string Y-m-d). Tanpa Batch.
     */
    public function importProduksi(string $path, ?int $userId = null, ?callable $onProgress = null, ?int $year = null, ?int $month = null): ImportResult
    {
        $records = [];
        $dates = [];
        foreach ($this->dataRowsSheet($path, 'ZPTPNHLPP039') as $v) {
            if ($this->isEmptyRow($v)) {
                continue;
            }
            $plant = trim((string) ($v[self::PRODUKSI_COLS['plant_code']] ?? ''));
            $date = $this->produksiDate($v[self::PRODUKSI_COLS['tgl_posting']] ?? null);
            if ($plant === '' || $date === null) {
                continue;
            }
            // Penjaga bulan: bila operator memilih periode, hanya tanggal pada
            // tahun+bulan tersebut yang diimpor (cegah file salah-bulan masuk).
            if ($year !== null && $month !== null
                && ((int) substr($date, 0, 4) !== $year || (int) substr($date, 5, 2) !== $month)) {
                continue;
            }
            $kebunCode = $this->produksiText($v[self::PRODUKSI_COLS['kebun_code']] ?? null, 20);
            // Lewati baris non-kebun yang tidak dipakai laporan produksi: kode PKS 5F* dan PLS.
            if ($this->isExcludedProduksiKebun($kebunCode)) {
                continue;
            }
            $dates[$date] = true;
            $records[] = [
                'posting_date' => $date,
                'plant_code' => $plant,
                'plant_desc' => $this->produksiText($v[self::PRODUKSI_COLS['plant_desc']] ?? null),
                'group_pemilik' => $this->produksiText($v[self::PRODUKSI_COLS['group_pemilik']] ?? null, 30),
                'kebun_code' => $kebunCode,
                'nama_kebun' => $this->produksiText($v[self::PRODUKSI_COLS['nama_kebun']] ?? null),
                'sisa_awal' => $this->produksiNum($v[self::PRODUKSI_COLS['sisa_awal']] ?? null),
                'tbs_diterima_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diterima_sdhari']] ?? null),
                'tbs_diterima_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diterima_sdbulan']] ?? null),
                'tbs_diolah_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diolah_sdhari']] ?? null),
                'tbs_diolah_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diolah_sdbulan']] ?? null),
                'sisa_akhir' => $this->produksiNum($v[self::PRODUKSI_COLS['sisa_akhir']] ?? null),
                'ms_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['ms_sdhari']] ?? null),
                'ms_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['ms_sdbulan']] ?? null),
                'is_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['is_sdhari']] ?? null),
                'is_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['is_sdbulan']] ?? null),
                'tidak_mengolah' => trim((string) ($v[self::PRODUKSI_COLS['tidak_mengolah']] ?? '')) !== '',
            ];
        }

        $inserted = 0;
        DB::transaction(function () use ($records, $dates, &$inserted, $onProgress): void {
            if ($dates !== []) {
                DB::table('produksi_pks')->whereIn('posting_date', array_keys($dates))->delete();
            }
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('produksi_pks')->insert($chunk);
                $inserted += count($chunk);
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            }
        });

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /**
     * Baris non-kebun yang tidak ditampilkan di laporan produksi:
     * kode PKS 5F* (mis. 5F08) dan PLS. Dipakai saat import (skip) maupun
     * pembersihan data lama.
     */
    public static function isExcludedProduksiKebun(?string $code): bool
    {
        $c = strtoupper(trim((string) $code));

        return $c !== '' && (str_starts_with($c, '5F') || $c === 'PLS');
    }

    private function produksiText(mixed $v, int $len = 150): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, $len);
    }

    private function produksiNum(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Serial Excel (mis. 46173) atau string 'Y-m-d' → 'Y-m-d'; null bila tak terbaca. */
    private function produksiDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $days = (int) round((float) $v);

            return (new \DateTime('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
        }
        $t = trim((string) $v);
        $d = \DateTime::createFromFormat('!Y-m-d', substr($t, 0, 10));

        return $d ? $d->format('Y-m-d') : null;
    }

    /** Indeks kolom 0-based sheet ZESTHLE020 yang dipakai untuk produksi kebun. */
    private const ZESTHLE_COLS = [
        'plant_code' => 0, 'plant_desc' => 1, 'goods_recipient' => 2, 'desc_plant_kebun' => 3,
        'afdeling' => 4, 'supplier_code' => 5, 'supplier_name' => 6, 'weight_netto' => 22, 'posting_date' => 12,
    ];

    /**
     * Pemetaan kode pabrik penerima → nama pendek "Short Plant" (kolom pivot Pembelian).
     * Rumus VLOOKUP Short Plant di Excel rusak (#REF), jadi diturunkan di sini.
     * Selaras dengan ProduksiController::PLANT_SHORT.
     */
    public const PLANT_SHORT = [
        '5F01' => 'Pagun', '5F04' => 'Parba', '5F07' => 'Panga', '5F08' => 'Papar', '5F09' => 'Pakem',
        '5F14' => 'Papam', '5F15' => 'Papel', '5F21' => 'Pasam', '5F22' => 'Palpi',
    ];

    /**
     * Impor sheet ZESTHLE020 → produksi_kebun_wb (idempoten per posting_date). Kolom
     * turunan dihitung di sini (rumus Excel-nya rusak/#REF):
     *  - supply           = Goods Recipient terisi ? "Kebun Sendiri" : "Pembelian"
     *  - kategori_pembelian = kode supplier diawali "25" ? "Kebun Plasma" : "Kebun Pihak 3"
     *  - short_plant      = PLANT_SHORT[plant_code] (pabrik penerima)
     */
    public function importProduksiKebun(string $path, ?int $userId = null, ?callable $onProgress = null, ?int $year = null, ?int $month = null): ImportResult
    {
        $records = [];
        $dates = [];
        $seen = 0;
        foreach ($this->dataRowsSheet($path, 'ZESTHLE020') as $v) {
            if ($this->isEmptyRow($v)) {
                continue;
            }
            $plant = strtoupper(trim((string) ($v[self::ZESTHLE_COLS['plant_code']] ?? '')));
            $date = $this->produksiDate($v[self::ZESTHLE_COLS['posting_date']] ?? null);
            if ($plant === '' || $date === null) {
                continue;
            }
            // Penjaga bulan: hanya baris pada tahun+bulan terpilih yang diimpor.
            if ($year !== null && $month !== null
                && ((int) substr($date, 0, 4) !== $year || (int) substr($date, 5, 2) !== $month)) {
                continue;
            }

            $goodsRecipient = $this->produksiText($v[self::ZESTHLE_COLS['goods_recipient']] ?? null, 20);
            $supplierCode = $this->produksiText($v[self::ZESTHLE_COLS['supplier_code']] ?? null, 30);
            $isKebunSendiri = $goodsRecipient !== null;
            $supply = $isKebunSendiri ? 'Kebun Sendiri' : 'Pembelian';
            $kategori = null;
            if (! $isKebunSendiri) {
                $kategori = str_starts_with((string) $supplierCode, '25') ? 'Kebun Plasma' : 'Kebun Pihak 3';
            }

            $seen++;
            if ($onProgress !== null && $seen % 500 === 0) {
                $onProgress($seen);
            }

            $dates[$date] = true;
            $records[] = [
                'posting_date' => $date,
                'plant_code' => $plant,
                'plant_desc' => $this->produksiText($v[self::ZESTHLE_COLS['plant_desc']] ?? null),
                'goods_recipient' => $goodsRecipient,
                'desc_plant_kebun' => $this->produksiText($v[self::ZESTHLE_COLS['desc_plant_kebun']] ?? null),
                'afdeling' => $this->produksiText($v[self::ZESTHLE_COLS['afdeling']] ?? null, 20),
                'supplier_code' => $isKebunSendiri ? null : $supplierCode,
                'supplier_name' => $isKebunSendiri ? null : $this->produksiText($v[self::ZESTHLE_COLS['supplier_name']] ?? null, 200),
                'weight_netto' => $this->produksiNum($v[self::ZESTHLE_COLS['weight_netto']] ?? null),
                'supply' => $supply,
                'kategori_pembelian' => $kategori,
                'short_plant' => $isKebunSendiri ? null : (self::PLANT_SHORT[$plant] ?? $plant),
            ];
        }

        $inserted = 0;
        DB::transaction(function () use ($records, $dates, &$inserted, $onProgress): void {
            if ($dates !== []) {
                DB::table('produksi_kebun_wb')->whereIn('posting_date', array_keys($dates))->delete();
            }
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('produksi_kebun_wb')->insert($chunk);
                $inserted += count($chunk);
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            }
        });

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /** Indeks kolom 0-based sheet "Data" workbook Pembelian TBS (ekspor SAP). */
    private const PEMBELIAN_TBS_COLS = [
        'posting_date' => 0, 'period' => 1, 'plant_code' => 2, 'plant_desc' => 3,
        'batch' => 4, 'vendor_code' => 5, 'vendor_name' => 6, 'uom' => 7,
        'qty' => 8, 'prelim_val' => 9, 'price_diff' => 10, 'actual_value' => 11,
        'price' => 12, 'jenis' => 14, 'contract' => 15, 'purch_order' => 16, 'mat_doc' => 17,
    ];

    /**
     * Impor sheet "Data" (ekspor SAP pembelian TBS) → pembelian_tbs.
     *
     * Satu file berisi banyak periode sekaligus (tahun berjalan) — idempoten
     * HAPUS-GANTI per (year, period) yang muncul di file; $year (bila diisi) menjadi
     * penjaga: baris tahun lain dilewati. Semua jenis dokumen (Good Receipt & Invoice)
     * dan nilai minus (koreksi) disimpan apa adanya — total per plant×batch×period
     * tervalidasi selisih 0 terhadap pivot workbook acuan. Baris dibaca streaming dan
     * disisipkan per-chunk (memori konstan; file ±131 ribu baris).
     */
    public function importPembelianTbs(string $path, ?int $userId = null, ?callable $onProgress = null, ?int $year = null): ImportResult
    {
        $C = self::PEMBELIAN_TBS_COLS;
        $inserted = 0;

        DB::transaction(function () use ($path, $C, $year, $onProgress, &$inserted): void {
            $records = [];
            $cleared = []; // "year-period" yang sudah dihapus-ganti
            $flush = function () use (&$records, &$inserted, $onProgress): void {
                if ($records === []) {
                    return;
                }
                DB::table('pembelian_tbs')->insert($records);
                $inserted += count($records);
                $records = [];
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            };

            foreach ($this->dataRowsSheet($path, 'Data') as $v) {
                if ($this->isEmptyRow($v)) {
                    continue;
                }
                $plant = strtoupper(trim((string) ($v[$C['plant_code']] ?? '')));
                $date = $this->pembelianDate($v[$C['posting_date']] ?? null);
                $period = is_numeric($v[$C['period']] ?? null) ? (int) $v[$C['period']] : null;
                if ($plant === '' || $date === null || $period === null || $period < 1 || $period > 12) {
                    continue;
                }
                $rowYear = (int) substr($date, 0, 4);
                // Penjaga tahun: hanya baris pada tahun terpilih yang diimpor.
                if ($year !== null && $rowYear !== $year) {
                    continue;
                }

                // Hapus-ganti data lama saat periode pertama kali dijumpai di file.
                $key = "{$rowYear}-{$period}";
                if (! isset($cleared[$key])) {
                    DB::table('pembelian_tbs')->where('year', $rowYear)->where('period', $period)->delete();
                    $cleared[$key] = true;
                }

                $records[] = [
                    'posting_date' => $date,
                    'year' => $rowYear,
                    'period' => $period,
                    'plant_code' => $plant,
                    'plant_desc' => $this->produksiText($v[$C['plant_desc']] ?? null),
                    'batch' => strtoupper((string) $this->produksiText($v[$C['batch']] ?? null, 12)),
                    'vendor_code' => $this->produksiText($v[$C['vendor_code']] ?? null, 30),
                    'vendor_name' => $this->produksiText($v[$C['vendor_name']] ?? null, 200),
                    'uom' => $this->produksiText($v[$C['uom']] ?? null, 10),
                    'qty' => $this->produksiNum($v[$C['qty']] ?? null),
                    'prelim_val' => $this->produksiNum($v[$C['prelim_val']] ?? null),
                    'price_diff' => $this->produksiNum($v[$C['price_diff']] ?? null),
                    'actual_value' => $this->produksiNum($v[$C['actual_value']] ?? null),
                    'price' => $this->produksiNum($v[$C['price']] ?? null),
                    'jenis' => $this->produksiText($v[$C['jenis']] ?? null, 30),
                    'contract' => $this->produksiText($v[$C['contract']] ?? null, 30),
                    'purch_order' => $this->produksiText($v[$C['purch_order']] ?? null, 30),
                    'mat_doc' => $this->produksiText($v[$C['mat_doc']] ?? null, 30),
                ];
                if (count($records) >= 500) {
                    $flush();
                }
            }
            $flush();
        });

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /** Indeks kolom 0-based sheet "Data" workbook Penjualan Produk (ekspor GL SAP). */
    private const PENJUALAN_COLS = [
        'document_number' => 0, 'posting_date' => 1, 'period' => 2, 'account' => 3,
        'reference' => 5, 'profit_center' => 8, 'profit_center_desc' => 9,
        'document_type' => 12, 'amount' => 14, 'gl_account_desc' => 18,
        'material_code' => 24, 'material_desc' => 25, 'qty' => 26, 'uom' => 27,
        'customer_code' => 31, 'customer_name' => 32,
    ];

    /**
     * Impor sheet "Data" (ekspor GL SAP penjualan) → penjualan_produk.
     *
     * Pola sama dgn importPembelianTbs: file berisi banyak periode → idempoten
     * HAPUS-GANTI per (year, period) yang muncul di file; $year = penjaga tahun.
     * Qty & Amount disimpan APA ADANYA (negatif = kredit pendapatan; koreksi positif
     * ikut) — total per material×(customer|profit center)×period tervalidasi selisih 0
     * terhadap pivot workbook acuan. Streaming + insert per-chunk (memori konstan).
     */
    public function importPenjualanProduk(string $path, ?int $userId = null, ?callable $onProgress = null, ?int $year = null): ImportResult
    {
        $C = self::PENJUALAN_COLS;
        $inserted = 0;

        DB::transaction(function () use ($path, $C, $year, $onProgress, &$inserted): void {
            $records = [];
            $cleared = []; // "year-period" yang sudah dihapus-ganti
            $flush = function () use (&$records, &$inserted, $onProgress): void {
                if ($records === []) {
                    return;
                }
                DB::table('penjualan_produk')->insert($records);
                $inserted += count($records);
                $records = [];
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            };

            foreach ($this->dataRowsSheet($path, 'Data') as $v) {
                if ($this->isEmptyRow($v)) {
                    continue;
                }
                $pc = strtoupper(trim((string) ($v[$C['profit_center']] ?? '')));
                $date = $this->pembelianDate($v[$C['posting_date']] ?? null);
                $period = is_numeric($v[$C['period']] ?? null) ? (int) $v[$C['period']] : null;
                if ($pc === '' || $date === null || $period === null || $period < 1 || $period > 12) {
                    continue;
                }
                $rowYear = (int) substr($date, 0, 4);
                // Penjaga tahun: hanya baris pada tahun terpilih yang diimpor.
                if ($year !== null && $rowYear !== $year) {
                    continue;
                }

                // Hapus-ganti data lama saat periode pertama kali dijumpai di file.
                $key = "{$rowYear}-{$period}";
                if (! isset($cleared[$key])) {
                    DB::table('penjualan_produk')->where('year', $rowYear)->where('period', $period)->delete();
                    $cleared[$key] = true;
                }

                $records[] = [
                    'document_number' => $this->produksiText($v[$C['document_number']] ?? null, 20),
                    'posting_date' => $date,
                    'year' => $rowYear,
                    'period' => $period,
                    'account' => $this->produksiText($v[$C['account']] ?? null, 20),
                    'gl_account_desc' => $this->produksiText($v[$C['gl_account_desc']] ?? null),
                    'profit_center' => $pc,
                    'profit_center_desc' => $this->produksiText($v[$C['profit_center_desc']] ?? null),
                    'material_code' => $this->produksiText($v[$C['material_code']] ?? null, 20),
                    'material_desc' => (string) $this->produksiText($v[$C['material_desc']] ?? null, 100),
                    'qty' => $this->produksiNum($v[$C['qty']] ?? null),
                    'uom' => $this->produksiText($v[$C['uom']] ?? null, 10),
                    'amount' => $this->produksiNum($v[$C['amount']] ?? null),
                    'customer_code' => $this->produksiText($v[$C['customer_code']] ?? null, 30),
                    'customer_name' => $this->produksiText($v[$C['customer_name']] ?? null, 200),
                    'document_type' => $this->produksiText($v[$C['document_type']] ?? null, 10),
                    'reference' => $this->produksiText($v[$C['reference']] ?? null, 30),
                ];
                if (count($records) >= 500) {
                    $flush();
                }
            }
            $flush();
        });

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    /** Tanggal pembelian: serial Excel / 'Y-m-d' (via produksiDate) atau teks 'm/d/Y'. */
    private function pembelianDate(mixed $v): ?string
    {
        $d = $this->produksiDate($v);
        if ($d !== null) {
            return $d;
        }
        $t = trim((string) ($v ?? ''));
        $p = \DateTime::createFromFormat('!n/j/Y', $t);

        return $p ? $p->format('Y-m-d') : null;
    }

    /**
     * Indeks kolom 0-based file biaya PKS (ekspor SAP cost, sheet pertama "Sheet1").
     * Hanya kolom yang dipakai LM16: identitas pabrik + kode pemetaan + nilai.
     *  - cost_center ← "Kode A" (AO): STAS (pengolahan), BT.. (overhead), SUP3 (depresiasi).
     *  - cost_element ← "Cost Element" (F): kode GL, dipakai untuk baris Biaya Pengolahan.
     * Pemetaan ke baris LM16 dilakukan di Lm16Service via lm16_account_map.
     */
    private const PKS_BIAYA_COLS = [
        'cost_element' => 5,   // F  Cost Element (GL)
        'period' => 7,         // H  Period (bulan 1-12)
        'nilai' => 9,          // J  Value in Obj. Crcy
        'plant_code' => 39,    // AN Plant (5F01..)
        'cost_center' => 40,   // AO Kode A (STAS/BT../SUP3)
    ];

    /**
     * Impor file biaya PKS (ekspor SAP cost, satu baris per posting) ke pks_biaya
     * (idempoten per batch). Penjaga bulan: hanya baris dengan Period = bulan batch yang
     * diimpor (cegah file salah-bulan masuk). Hanya unit PABRIK dikenal yang diterima;
     * plant lain dicatat sebagai error & dilewati. Baris disisipkan per-chunk (memori konstan).
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importPksBiayaFlat(Batch $batch, iterable $rows, ?callable $onProgress = null): ImportResult
    {
        DB::table('pks_biaya')->where('batch_id', $batch->id)->delete();

        $C = self::PKS_BIAYA_COLS;
        $month = (int) $batch->month;
        // Label kolom asli file (untuk menyimpan baris mentah apa adanya ke kolom `raw`,
        // dipakai drill-down LM16 level-2). Urutan & label = template pks_biaya.
        $headers = ImportTemplateService::specs()['pks_biaya']['headers'];
        $records = [];
        $inserted = 0;
        $skippedPlant = [];

        $flush = function () use (&$records, &$inserted, $onProgress): void {
            if ($records === []) {
                return;
            }
            DB::table('pks_biaya')->insert($records);
            $inserted += count($records);
            $records = [];
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
        };

        foreach ($rows as $v) {
            if ($this->isEmptyRow($v)) {
                continue;
            }
            $period = is_numeric($v[$C['period']] ?? null) ? (int) $v[$C['period']] : null;
            // Penjaga bulan: hanya baris periode = bulan batch yang diimpor.
            if ($period !== $month) {
                continue;
            }
            $plant = strtoupper(trim((string) ($v[$C['plant_code']] ?? '')));
            if ($plant === '') {
                continue;
            }
            // Hanya unit PABRIK yang dikenal; lainnya dicatat & dilewati (audit error).
            if (! $this->isKnownUnit($plant, 'PABRIK')) {
                $skippedPlant[$plant] = ($skippedPlant[$plant] ?? 0) + 1;

                continue;
            }

            // Simpan baris mentah apa adanya (semua kolom asli file) → kolom raw.
            $raw = [];
            foreach ($headers as $i => $label) {
                $cell = $v[$i] ?? null;
                $raw[$label] = (is_scalar($cell) || $cell === null) ? $cell : (string) $cell;
            }

            $records[] = [
                'batch_id' => $batch->id,
                'plant_code' => $plant,
                'period' => $period,
                'cost_center' => $this->nullableText($v[$C['cost_center']] ?? null),
                'cost_element' => $this->nullableText($v[$C['cost_element']] ?? null),
                'klasifikasi_code' => null,
                'nilai' => round($this->numericValue($v[$C['nilai']] ?? 0), 2),
                'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            if (count($records) >= 500) {
                $flush();
            }
        }
        $flush();

        $errors = [];
        foreach ($skippedPlant as $code => $n) {
            $errors[] = "Plant di luar master pabrik dilewati: {$code} ({$n} baris).";
        }

        return new ImportResult($inserted, array_slice($errors, 0, 50));
    }

    /**
     * Impor data mentah (WBS/GC/OHC) ke tabel staging: ganti data batch, lalu insert
     * semua baris per-chunk. $rows berupa generator streaming agar memori tetap konstan.
     *
     * @param  array<int, string>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importRaw(Batch $batch, string $table, array $columns, iterable $rows, string $kind, ?callable $onProgress = null): ImportResult
    {
        DB::table($table)->where('batch_id', $batch->id)->delete();

        $records = [];
        $inserted = 0;
        foreach ($rows as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $record = ['batch_id' => $batch->id];
            foreach ($columns as $index => $column) {
                $record[$column] = $this->rawCell($values[$index] ?? null, isset(self::NUMERIC_RAW_COLUMNS[$column]));
            }

            $record['plant_code'] = $kind === 'wbs'
                ? $this->nullableText($record['plant'] ?? null)
                : $this->plantFromCostCenter($record['cost_center'] ?? null);

            $records[] = $record;

            if (count($records) >= 500) {
                DB::table($table)->insert($records);
                $inserted += count($records);
                $records = [];
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            }
        }

        if ($records !== []) {
            DB::table($table)->insert($records);
            $inserted += count($records);
        }

        if ($onProgress !== null) {
            $onProgress($inserted);
        }

        return new ImportResult($inserted, []);
    }

    private function rawCell(mixed $value, bool $numeric): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if ($numeric) {
            return is_numeric($value) ? (float) $value : $this->nullableNumber($value);
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 250);
    }

    private function plantFromCostCenter(mixed $costCenter): ?string
    {
        $text = $this->nullableText($costCenter);

        return $text === null ? null : mb_substr($text, 0, 4);
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function readWorkbook(string $path): array
    {
        $arrays = Excel::toArray(new RawWorkbookImport, $path);
        $sheetNames = IOFactory::load($path)->getSheetNames();

        return collect($arrays)
            ->mapWithKeys(fn (array $rows, int $index) => [$sheetNames[$index] ?? "Sheet {$index}" => $rows])
            ->all();
    }

    private function importPksBiaya(Batch $batch, array $workbook): ImportResult
    {
        DB::table('pks_biaya')->where('batch_id', $batch->id)->delete();

        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'Summary'), [
            'plant_code' => ['plant', 'plantcode', 'pabrik', 'uraian'],
            'period' => ['period', 'periode'],
            'cost_center' => ['costcenter', 'costctr', 'cc', 'kodea', 'kodeb'],
            'cost_element' => ['costelement', 'gl'],
            'klasifikasi_code' => ['klasifikasi', 'klasifikasicode'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'nilai']);

        $inserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? null);

            if (! $this->isKnownUnit($plantCode, 'PABRIK')) {
                $errors[] = "Summary baris {$index}: pabrik tidak dikenal.";

                continue;
            }

            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $this->int($row['period'] ?? $batch->month),
                'cost_center' => $this->nullableText($row['cost_center'] ?? null),
                'cost_element' => $this->nullableText($row['cost_element'] ?? null),
                'klasifikasi_code' => $this->klasifikasiCode($row['klasifikasi_code'] ?? null),
                'nilai' => $this->number($row['nilai'] ?? 0),
            ]);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importPksProduksi(Batch $batch, array $workbook): ImportResult
    {
        DB::table('pks_produksi')->where('batch_id', $batch->id)->delete();

        $lm625Sheets = collect($workbook)
            ->keys()
            ->filter(fn (string $name) => preg_match('/^LM625F\d{2}/i', $name))
            ->values();

        if ($lm625Sheets->isNotEmpty()) {
            $records = [];
            foreach ($lm625Sheets as $sheetName) {
                $records = [...$records, ...$this->positionalPksProduksi($batch, $sheetName, $this->sheet($workbook, $sheetName))];
            }

            return $this->insertPksProduksiRecords($records);
        }

        $sheetName = collect(array_keys($workbook))->first(fn (string $name) => str_starts_with(strtoupper($name), 'LM625F')) ?? 'LM625F01';
        [$rows, $errors] = $this->tableRows($this->sheet($workbook, $sheetName), [
            'plant_code' => ['plant', 'plantcode', 'pabrik'],
            'period' => ['period', 'periode'],
            'uraian' => ['uraian', 'keterangan'],
            'nilai_bi' => ['nilaibi', 'bulanini', 'bi'],
            'nilai_sd' => ['nilaisd', 'sdbulanini', 'sd'],
        ], ['uraian']);

        return $this->insertPksProduksiRecords(collect($rows)->map(fn (array $row) => [
            'batch_id' => $batch->id,
            'plant_code' => $this->text($row['plant_code'] ?? $this->plantCodeFromLm625Sheet($sheetName)),
            'period' => $this->int($row['period'] ?? $batch->month),
            'uraian' => $this->text($row['uraian'] ?? null),
            'nilai_bi' => $this->number($row['nilai_bi'] ?? 0),
            'nilai_sd' => $this->number($row['nilai_sd'] ?? 0),
        ])->all(), $errors);
    }

    private function importAlokasiProduksi(Batch $batch, array $workbook): ImportResult
    {
        DB::table('alokasi_produksi')->where('batch_id', $batch->id)->delete();

        $rows = $this->sheet($workbook, 'Alokasi');
        $errors = [];

        if ($this->looksLikeAlokasiMatrix($rows)) {
            $records = $this->positionalAlokasiProduksi($batch, $rows);
        } else {
            [$tableRows, $errors] = $this->tableRows($rows, [
                'year' => ['year', 'tahun'],
                'month' => ['month', 'bulan'],
                'kebun_code' => ['kebuncode', 'kebun', 'plant'],
                'pabrik_code' => ['pabrikcode', 'pabrik'],
                'produk' => ['produk'],
                'jumlah' => ['jumlah', 'nilai'],
            ], ['kebun_code', 'produk', 'jumlah']);

            $records = [];
            foreach ($tableRows as $index => $row) {
                $records[] = [
                    'batch_id' => $batch->id,
                    'year' => $this->int($row['year'] ?? $batch->year),
                    'month' => $this->int($row['month'] ?? $batch->month),
                    'kebun_code' => $this->text($row['kebun_code'] ?? null),
                    'pabrik_code' => $this->nullableText($row['pabrik_code'] ?? null),
                    'produk' => $this->text($row['produk'] ?? null),
                    'jumlah' => $this->number($row['jumlah'] ?? 0),
                ];
            }
        }

        $inserted = 0;
        foreach ($records as $index => $record) {
            if (! $this->isKnownUnit($record['kebun_code'], 'KEBUN')) {
                $errors[] = "Alokasi produksi baris {$index}: kebun tidak dikenal.";

                continue;
            }

            DB::table('alokasi_produksi')->insert($record);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importAlokasiAreal(Batch $batch, array $workbook): ImportResult
    {
        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'Alokasi'), [
            'year' => ['year', 'tahun'],
            'kebun_code' => ['kebuncode', 'kebun', 'plant'],
            'real_thn_lalu' => ['realthnlalu', 'realisasitahunlalu'],
            'real_thn_ini' => ['realthnini', 'realisasitahunini'],
            'rko' => ['rko'],
            'rkap' => ['rkap'],
        ], ['kebun_code']);

        $upserted = 0;
        foreach ($rows as $index => $row) {
            $kebunCode = $this->text($row['kebun_code'] ?? null);
            if (! $this->isKnownUnit($kebunCode, 'KEBUN')) {
                $errors[] = "Alokasi areal baris {$index}: kebun tidak dikenal.";

                continue;
            }

            DB::table('alokasi_areal')->updateOrInsert(
                ['year' => $this->int($row['year'] ?? $batch->year), 'kebun_code' => $kebunCode],
                [
                    'real_thn_lalu' => $this->number($row['real_thn_lalu'] ?? 0),
                    'real_thn_ini' => $this->number($row['real_thn_ini'] ?? 0),
                    'rko' => $this->number($row['rko'] ?? 0),
                    'rkap' => $this->number($row['rkap'] ?? 0),
                ],
            );
            $upserted++;
        }

        return new ImportResult($upserted, $errors);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $errors
     */
    private function insertPksProduksiRecords(array $records, array $errors = []): ImportResult
    {
        $inserted = 0;
        foreach ($records as $index => $record) {
            if (! $this->isKnownUnit($record['plant_code'], 'PABRIK')) {
                $errors[] = "Produksi pabrik baris {$index}: pabrik tidak dikenal.";

                continue;
            }

            DB::table('pks_produksi')->insert($record);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importTahunLalu(Batch $batch, array $workbook): ImportResult
    {
        $sheet = $this->sheet($workbook, 'Tahun Lalu');

        $matrix = $this->detectTahunLaluMatrix($sheet);
        if ($matrix !== null) {
            return $this->importTahunLaluMatrix($batch, $sheet, $matrix);
        }

        return $this->importTahunLaluTabular($batch, $sheet);
    }

    /**
     * Format matriks (sheet "Tahun Lalu" asli): kode baris di satu kolom (mis. B),
     * kode unit kebun tersebar sebagai kolom (5E01..5E19), tiap sel = realisasi
     * tahun lalu s.d bulan laporan untuk kombinasi kode+unit tersebut.
     *
     * @param  array{headerIndex: int, plantColumns: array<int, string>}  $matrix
     */
    private function importTahunLaluMatrix(Batch $batch, array $sheet, array $matrix): ImportResult
    {
        $headerIndex = $matrix['headerIndex'];
        $plantColumns = $matrix['plantColumns'];
        $firstPlantColumn = min(array_keys($plantColumns));

        $kodeColumn = $this->detectKodeColumn($sheet, $headerIndex, $firstPlantColumn);
        $year = $this->detectTahunLaluYear($sheet) ?? ($batch->year - 1);
        $period = $this->detectTahunLaluPeriod($sheet) ?? $batch->month;
        $komoditi = $this->detectTahunLaluKomoditi($sheet);
        $reportType = 'LM14';

        DB::table('realisasi_tahun_lalu')
            ->where('year', $year)
            ->where('report_type', $reportType)
            ->where('period', $period)
            ->whereIn('plant_code', array_values($plantColumns))
            ->when(
                $komoditi !== null,
                fn ($query) => $query->where('komoditi', $komoditi),
                fn ($query) => $query->whereNull('komoditi'),
            )
            ->delete();

        $records = [];
        foreach (array_slice($sheet, $headerIndex + 1) as $values) {
            $kode = $this->nullableText($values[$kodeColumn] ?? null);
            if (! $this->looksLikeTahunLaluKode($kode)) {
                continue;
            }

            foreach ($plantColumns as $column => $plantCode) {
                $nilai = $this->matrixNumber($values[$column] ?? null);
                if ($nilai === null || abs($nilai) < 0.00001) {
                    continue;
                }

                $records[] = [
                    'year' => $year,
                    'komoditi' => $komoditi,
                    'plant_code' => $plantCode,
                    'report_type' => $reportType,
                    'kode' => $kode,
                    'period' => $period,
                    'nilai' => $nilai,
                ];
            }
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('realisasi_tahun_lalu')->insert($chunk);
        }

        return new ImportResult(count($records), []);
    }

    private function importTahunLaluTabular(Batch $batch, array $sheet): ImportResult
    {
        [$rows, $errors] = $this->tableRows($sheet, [
            'year' => ['year', 'tahun'],
            'komoditi' => ['komoditi', 'budidaya'],
            'plant_code' => ['plant', 'plantcode', 'kodeunit'],
            'report_type' => ['reporttype', 'laporan'],
            'kode' => ['kode', 'kodebaris'],
            'period' => ['period', 'periode'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'report_type', 'kode', 'period', 'nilai']);

        $upserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? null);
            $reportType = $this->reportType($row['report_type'] ?? null);

            if (! $this->isKnownUnit($plantCode) || $reportType === null) {
                $errors[] = "Tahun lalu baris {$index}: plant/report_type tidak valid.";

                continue;
            }

            DB::table('realisasi_tahun_lalu')->updateOrInsert(
                [
                    'year' => $this->int($row['year'] ?? $batch->year - 1),
                    'komoditi' => $this->nullableKomoditi($row['komoditi'] ?? null),
                    'plant_code' => $plantCode,
                    'report_type' => $reportType,
                    'kode' => $this->text($row['kode'] ?? null),
                    'period' => $this->int($row['period'] ?? $batch->month),
                ],
                ['nilai' => $this->number($row['nilai'] ?? 0)],
            );
            $upserted++;
        }

        return new ImportResult($upserted, $errors);
    }

    /**
     * Deteksi baris header matriks (yang memuat >=3 kode unit sebagai kolom).
     *
     * @return array{headerIndex: int, plantColumns: array<int, string>}|null
     */
    private function detectTahunLaluMatrix(array $sheet): ?array
    {
        foreach (array_slice($sheet, 0, 15, true) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $plantColumns = [];
            foreach ($row as $column => $value) {
                $code = $this->nullableText($value);
                if ($code !== null && $this->isKnownUnit($code)) {
                    $plantColumns[$column] = $code;
                }
            }

            if (count($plantColumns) >= 3) {
                return ['headerIndex' => $index, 'plantColumns' => $plantColumns];
            }
        }

        return null;
    }

    private function detectKodeColumn(array $sheet, int $headerIndex, int $firstPlantColumn): int
    {
        $best = 0;
        $bestCount = -1;
        for ($column = 0; $column < max(1, $firstPlantColumn); $column++) {
            $count = 0;
            foreach (array_slice($sheet, $headerIndex + 1, 120) as $values) {
                if ($this->looksLikeTahunLaluKode($this->nullableText($values[$column] ?? null))) {
                    $count++;
                }
            }

            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $column;
            }
        }

        return $best;
    }

    private function looksLikeTahunLaluKode(?string $kode): bool
    {
        return $kode !== null && preg_match('/^\d{2}-\d{2}\.?$/', $kode) === 1;
    }

    private function detectTahunLaluYear(array $sheet): ?int
    {
        foreach (array_slice($sheet, 0, 8) as $row) {
            foreach ((array) $row as $value) {
                if (preg_match('/(20\d{2})/', (string) $value, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    private function detectTahunLaluPeriod(array $sheet): ?int
    {
        $months = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        ];

        foreach (array_slice($sheet, 0, 8) as $row) {
            foreach ((array) $row as $value) {
                $key = mb_strtolower(trim((string) $value));
                if (isset($months[$key])) {
                    return $months[$key];
                }
            }
        }

        return null;
    }

    private function detectTahunLaluKomoditi(array $sheet): ?string
    {
        foreach (array_slice($sheet, 0, 8) as $row) {
            foreach ((array) $row as $value) {
                $text = strtoupper(trim((string) $value));
                if ($text === 'KS' || $text === 'KR') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Ambil angka dari sel matriks. Mendukung ekspor Google Sheets berbentuk
     * =IFERROR(__xludf.DUMMYFUNCTION(...), <nilai cache>) dengan mengambil nilai cache.
     */
    private function matrixNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $string = trim((string) $value);
        if (preg_match('/,\s*(-?\d+(?:\.\d+)?)\s*\)\s*$/', $string, $matches)) {
            return (float) $matches[1];
        }

        if (str_starts_with($string, '=')) {
            return null;
        }

        return $this->nullableNumber($value);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function tableRows(array $sheetRows, array $aliases, array $required): array
    {
        $errors = [];
        $headerIndex = $this->findHeaderIndex($sheetRows, $aliases, $required);
        if ($headerIndex === null) {
            return [[], ['Header kolom tidak ditemukan.']];
        }

        $headers = $this->headers($sheetRows[$headerIndex], $aliases);
        $rows = [];
        foreach (array_slice($sheetRows, $headerIndex + 1) as $offset => $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $row = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $row[$field] = $values[$column] ?? null;
                }
            }

            if ($this->requiredMissing($row, $required)) {
                continue;
            }

            $rows[$headerIndex + 2 + $offset] = $row;
        }

        return [$rows, $errors];
    }

    private function findHeaderIndex(array $rows, array $aliases, array $required): ?int
    {
        foreach (array_slice($rows, 0, 40, true) as $index => $row) {
            $fields = array_filter($this->headers($row, $aliases));
            $missing = array_diff($required, $fields);
            if ($missing === []) {
                return $index;
            }
        }

        return null;
    }

    private function headers(array $row, array $aliases): array
    {
        return collect($row)->map(function (mixed $value) use ($aliases): ?string {
            $normalized = $this->normalize($value);
            foreach ($aliases as $field => $names) {
                if (in_array($normalized, $names, true)) {
                    return $field;
                }
            }

            return null;
        })->all();
    }

    private function requiredMissing(array $row, array $required): bool
    {
        foreach ($required as $field) {
            if (($row[$field] ?? null) !== null && trim((string) $row[$field]) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sheet(array $workbook, string $name): array
    {
        foreach ($workbook as $sheetName => $rows) {
            if (strcasecmp($sheetName, $name) === 0) {
                return $rows;
            }
        }

        return $this->firstSheet($workbook);
    }

    private function firstSheet(array $workbook): array
    {
        return reset($workbook) ?: [];
    }

    private function positionalAlokasiProduksi(Batch $batch, array $rows): array
    {
        $records = [];
        $pabrikCodes = [];
        foreach (($rows[1] ?? []) as $column => $value) {
            $code = $this->nullableText($value);
            if ($code !== null && preg_match('/^5F\d{2}$/', $code)) {
                $pabrikCodes[$column] = $code;
            }
        }

        foreach ($rows as $row) {
            $kebunCode = $this->nullableText($row[1] ?? null);
            $produk = $this->nullableText($row[13] ?? null);

            if ($kebunCode === null || $produk === null) {
                continue;
            }

            foreach ($pabrikCodes as $column => $pabrikCode) {
                $jumlah = $this->nullableNumber($row[$column] ?? null);
                if ($jumlah === null || abs($jumlah) < 0.00001) {
                    continue;
                }

                $records[] = [
                    'batch_id' => $batch->id,
                    'year' => $batch->year,
                    'month' => $this->int($row[12] ?? $batch->month),
                    'kebun_code' => $kebunCode,
                    'pabrik_code' => $pabrikCode,
                    'produk' => $produk,
                    'jumlah' => $jumlah,
                ];
            }
        }

        return $records;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionalPksProduksi(Batch $batch, string $sheetName, array $rows): array
    {
        $plantCode = $this->plantCodeFromLm625Sheet($sheetName);
        if ($plantCode === null) {
            return [];
        }

        $labels = [
            'Jumlah Produksi TBS',
            'Jumlah TBS Diolah',
            'Jumlah Sisa Buah di Pabrik',
            'Jlh. Prod. Minyak Sawit',
            'Jumlah Produksi Inti Sawit',
        ];

        $records = [];
        foreach ($rows as $row) {
            $uraian = $this->nullableText($row[1] ?? null);
            if ($uraian === null || ! in_array($uraian, $labels, true)) {
                continue;
            }

            $currentBi = $uraian === 'Jumlah Sisa Buah di Pabrik'
                ? $this->number($row[3] ?? 0)
                : $this->number($row[4] ?? 0);
            $currentSd = $uraian === 'Jumlah Sisa Buah di Pabrik'
                ? $currentBi
                : $this->number($row[5] ?? 0);

            $records[] = [
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $batch->month,
                'uraian' => $uraian,
                'nilai_bi' => $currentBi,
                'nilai_sd' => $currentSd,
            ];

            if ($batch->month > 1) {
                $previousBi = $uraian === 'Jumlah Sisa Buah di Pabrik'
                    ? $this->number($row[18] ?? 0)
                    : $this->number($row[19] ?? 0);
                $previousSd = $uraian === 'Jumlah Sisa Buah di Pabrik'
                    ? $previousBi
                    : $this->number($row[20] ?? 0);

                $records[] = [
                    'batch_id' => $batch->id,
                    'plant_code' => $plantCode,
                    'period' => $batch->month - 1,
                    'uraian' => $uraian,
                    'nilai_bi' => $previousBi,
                    'nilai_sd' => $previousSd,
                ];
            }
        }

        return $records;
    }

    private function looksLikeAlokasiMatrix(array $rows): bool
    {
        return collect($rows[1] ?? [])->contains(fn (mixed $value) => is_string($value) && preg_match('/^5F\d{2}$/', trim($value)));
    }

    private function plantCodeFromLm625Sheet(string $sheetName): ?string
    {
        if (preg_match('/LM625F(\\d{2})/i', $sheetName, $matches)) {
            return '5F'.$matches[1];
        }

        return null;
    }

    private function isKnownUnit(?string $code, ?string $type = null): bool
    {
        if ($code === null || ! isset($this->knownUnitCodes[$code])) {
            return false;
        }

        return $type === null || $this->knownUnitCodes[$code] === $type;
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value))) ?? '';
    }

    private function komoditi(mixed $value): ?string
    {
        $text = strtoupper(trim((string) $value));

        return match (true) {
            str_contains($text, 'KS'), str_contains($text, 'SAWIT') => 'KS',
            str_contains($text, 'KR'), str_contains($text, 'KARET') => 'KR',
            default => null,
        };
    }

    private function nullableKomoditi(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : $this->komoditi($value);
    }

    private function reportType(mixed $value): ?string
    {
        $text = strtoupper(trim((string) $value));

        return in_array($text, ['LM14', 'LM13', 'LM16'], true) ? $text : null;
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function klasifikasiCode(mixed $value): ?string
    {
        $text = $this->nullableText($value);
        if ($text === null) {
            return null;
        }

        if (preg_match('/^\d+/', $text, $matches)) {
            return $matches[0];
        }

        return $text;
    }

    private function int(mixed $value): int
    {
        return (int) $this->number($value);
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->number($value);
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return 0.0;
        }

        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);

        return (float) $text;
    }

    /**
     * Parse nilai numerik dari file budget (mendukung format Indonesia dan Inggris).
     */
    private function numericValue(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return 0.0;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $v);
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn (mixed $value) => trim((string) $value) !== '')->isEmpty();
    }
}
