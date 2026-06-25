<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use AuthorizesReportRequests;

    /**
     * GET /api/report/lm14?batch=1&unit=5E11&komoditi=KS
     */
    public function lm14(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $request->validate([
            'batch' => 'required',
            'unit' => 'required',
            'komoditi' => 'required',
        ]);

        $batch = $this->findBatch($request->batch);
        $komoditi = strtoupper($request->komoditi);
        $isAll = $this->isAllUnits($request);
        $unit = $isAll ? $this->allUnitsPlaceholder('KEBUN', $komoditi) : $this->findUnit($request->unit);

        // Otorisasi: Viewer hanya boleh lihat batch final/locked
        $this->checkBatchAccess($batch);

        // Ambil data report. "Semua Unit" = jumlahkan nilai semua unit komoditi ini
        // (subtotal/total tetap konsisten karena penjumlahan aditif), capaian dihitung
        // ulang dari nilai gabungan.
        $rows = $isAll
            ? $this->aggregateLm14Rows($batch, $komoditi)
            : DB::table('report_lm14')
                ->join('lm_template_row', 'report_lm14.template_id', '=', 'lm_template_row.id')
                ->where('report_lm14.batch_id', $batch->id)
                ->where('report_lm14.unit_id', $unit->id)
                ->where('lm_template_row.komoditi', $komoditi)
                ->select(
                    'lm_template_row.urutan',
                    'lm_template_row.kode',
                    'lm_template_row.uraian',
                    'lm_template_row.row_type',
                    'lm_template_row.indent',
                    'report_lm14.*'
                )
                ->orderBy('lm_template_row.urutan')
                ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Data laporan LM14 tidak ditemukan. Silakan generate terlebih dahulu.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unit, 'LM14', $komoditi),
            'columns' => $this->getLm14Columns(),
            'rows' => $rows->map(fn ($row) => $this->formatLm14Row($row)),
        ]);
    }

    /**
     * GET /api/report/lm13?batch=1&unit=5E11&komoditi=KS
     */
    public function lm13(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $request->validate([
            'batch' => 'required',
            'unit' => 'required',
            'komoditi' => 'required',
        ]);

        $batch = $this->findBatch($request->batch);
        $komoditi = strtoupper($request->komoditi);
        $isAll = $this->isAllUnits($request);
        $unit = $isAll ? $this->allUnitsPlaceholder('KEBUN', $komoditi) : $this->findUnit($request->unit);

        $this->checkBatchAccess($batch);

        $rows = $isAll
            ? $this->aggregateLm13Rows($batch, $komoditi)
            : DB::table('report_lm13')
                ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
                ->where('report_lm13.batch_id', $batch->id)
                ->where('report_lm13.unit_id', $unit->id)
                ->where('lm_template_row.komoditi', $komoditi)
                ->select(
                    'lm_template_row.urutan',
                    'lm_template_row.kode',
                    'lm_template_row.uraian',
                    'lm_template_row.row_type',
                    'lm_template_row.indent',
                    'report_lm13.*'
                )
                ->orderBy('report_lm13.blok', 'asc') // Fix: blok bukan block
                ->orderBy('lm_template_row.urutan')
                ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Data laporan LM13 tidak ditemukan. Silakan generate terlebih dahulu.',
            ], 404);
        }

        // Sisipkan baris "Jumlah" untuk seksi A (Saldo Awal) — turunan dari baris
        // detail yang sudah terverifikasi, tidak mengubah template/mesin hitung.
        $rows = $this->withSaldoAwalJumlah($rows);

        $meta = $this->buildMeta($batch, $unit, 'LM13', $komoditi);
        $meta['area'] = $isAll
            ? $this->lm13AreaValuesAll($batch, $komoditi)
            : $this->lm13AreaValues($batch, $unit, $komoditi);

        return response()->json([
            'success' => true,
            'meta' => $meta,
            'columns' => $this->getLm13Columns(),
            'rows' => $rows->map(fn ($row) => $this->formatLm13Row($row)),
        ]);
    }

    /**
     * Luas Area Kebun (Ha) untuk header LM13 — sumber tabel alokasi_areal (blok
     * "III. Areal" sheet Alokasi). Nilai sama untuk Bulan Ini & s.d, dan sama di
     * semua blok (OLAH_JUAL/OLAH/JUAL) karena luas areal milik kebun, bukan per olah.
     *
     * @return array<string, float>
     */
    private function lm13AreaValues(Batch $batch, RefUnit $unit, string $komoditi): array
    {
        // Luas areal karet belum tersedia sumbernya; alokasi_areal hanya berisi sawit
        // (tanpa kolom komoditi) → jangan pakai nilai sawit untuk karet.
        if (strtoupper($komoditi) === 'KR') {
            return ['real_thn_lalu' => 0.0, 'real_thn_ini' => 0.0, 'rko' => 0.0, 'rkap' => 0.0];
        }

        $area = DB::table('alokasi_areal')
            ->where('year', $batch->year)
            ->where('kebun_code', $unit->code)
            ->first();

        return [
            'real_thn_lalu' => (float) ($area->real_thn_lalu ?? 0),
            'real_thn_ini' => (float) ($area->real_thn_ini ?? 0),
            'rko' => (float) ($area->rko ?? 0),
            'rkap' => (float) ($area->rkap ?? 0),
        ];
    }

    /**
     * Luas Area Kebun (Ha) konsolidasi "Semua Unit": jumlahkan alokasi_areal seluruh
     * kebun pada tahun batch. Karet belum tersedia sumbernya → nol (sama dgn per unit).
     *
     * @return array<string, float>
     */
    private function lm13AreaValuesAll(Batch $batch, string $komoditi): array
    {
        if (strtoupper($komoditi) === 'KR') {
            return ['real_thn_lalu' => 0.0, 'real_thn_ini' => 0.0, 'rko' => 0.0, 'rkap' => 0.0];
        }

        $area = DB::table('alokasi_areal')
            ->where('year', $batch->year)
            ->selectRaw('SUM(real_thn_lalu) as real_thn_lalu, SUM(real_thn_ini) as real_thn_ini, SUM(rko) as rko, SUM(rkap) as rkap')
            ->first();

        return [
            'real_thn_lalu' => (float) ($area->real_thn_lalu ?? 0),
            'real_thn_ini' => (float) ($area->real_thn_ini ?? 0),
            'rko' => (float) ($area->rko ?? 0),
            'rkap' => (float) ($area->rkap ?? 0),
        ];
    }

    /**
     * GET /api/report/lm16?batch=1&unit=5F01
     */
    public function lm16(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $request->validate([
            'batch' => 'required',
            'unit' => 'required',
        ]);

        $batch = $this->findBatch($request->batch);
        $unit = $this->findUnit($request->unit);

        $this->checkBatchAccess($batch);

        $rows = DB::table('report_lm16')
            ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
            ->where('report_lm16.batch_id', $batch->id)
            ->where('report_lm16.unit_id', $unit->id)
            ->select(
                'lm_template_row.urutan',
                'lm_template_row.kode',
                'lm_template_row.uraian',
                'lm_template_row.row_type',
                'lm_template_row.indent',
                'report_lm16.*'
            )
            ->orderBy('lm_template_row.urutan')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Data laporan LM16 tidak ditemukan. Silakan generate terlebih dahulu.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unit, 'LM16'),
            'columns' => $this->getLm16Columns($unit),
            'rows' => $rows->map(fn ($row) => $this->formatLm16Row($row)),
        ]);
    }

    /**
     * GET /api/report/drilldown?type&batch&unit&komoditi&kode&column
     */
    public function drilldown(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $request->validate([
            'type' => 'required|in:LM14,LM13,LM16,lm14,lm13,lm16',
            'batch' => 'required',
            'unit' => 'required',
            'kode' => 'required',
            'column' => 'required',
            'komoditi' => 'nullable|in:KS,KR,ks,kr',
        ]);

        $type = strtoupper((string) $request->type);
        $batch = $this->findBatch((string) $request->batch);
        $komoditi = $request->filled('komoditi') ? strtoupper((string) $request->komoditi) : null;
        $isAll = $this->isAllUnits($request);
        // ALL = tanpa filter plant_code (semua unit komoditi); placeholder hanya untuk meta.
        $unit = $isAll ? null : $this->findUnit((string) $request->unit);
        $unitMeta = $unit ?? $this->allUnitsPlaceholder('KEBUN', $komoditi);

        $this->checkBatchAccess($batch);

        $template = LmTemplateRow::query()
            ->where('report_type', $type)
            ->when($type !== 'LM16', fn ($query) => $query->where('komoditi', $komoditi))
            ->where(fn ($query) => $query
                ->where('kode', $request->kode)
                ->orWhere('urutan', is_numeric($request->kode) ? (int) $request->kode : 0))
            ->orderBy('urutan')
            ->first();

        $columnKey = (string) $request->column;

        $templateMeta = $template ? [
            'urutan' => $template->urutan,
            'kode' => $template->kode,
            'uraian' => $template->uraian,
            'row_type' => $template->row_type,
            'source' => $template->source,
        ] : null;

        // Kolom RKO/RKAP (anggaran): tampilkan DETAIL SUMBER per-baris LANGSUNG (tanpa
        // pivot perantara) dari budget_source. RKO=RKAP; bulan-ini vs s.d difilter periode.
        $budgetDetail = $this->budgetSourceDetail($type, $batch, $unit, $komoditi, $template, $columnKey);
        if ($budgetDetail !== null) {
            return response()->json([
                'success' => true,
                'meta' => $this->buildMeta($batch, $unitMeta, $type, $komoditi),
                'context' => [
                    'type' => $type,
                    'kode_baris' => (string) $request->kode,
                    'column_key' => $columnKey,
                    'column_label' => $this->columnLabel($type, $columnKey),
                    'template' => $templateMeta,
                    'direct_detail' => true,
                    'message' => $budgetDetail['row_count'] === 0
                        ? 'Tidak ada baris sumber RKO/RKAP untuk sel ini (anggaran 0 atau belum diimpor).'
                        : null,
                ],
                'pivot' => null,
                'detail' => $budgetDetail,
            ]);
        }

        $pivot = $this->drilldownPivot($type, $batch, $unit, $komoditi, $template, $columnKey);

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unitMeta, $type, $komoditi),
            'context' => [
                'type' => $type,
                'kode_baris' => (string) $request->kode,
                'column_key' => $columnKey,
                'column_label' => $this->columnLabel($type, $columnKey),
                'template' => $templateMeta,
                'direct_detail' => false,
                'message' => $pivot === null
                    ? 'Kolom ini tidak memiliki rincian sumber mentah (mis. anggaran/RKO/RKAP, capaian %, atau baris BTL/gaji staf tahun lalu yang menunggu data OHC).'
                    : null,
            ],
            'pivot' => $pivot,
        ]);
    }

    /**
     * GET /api/report/drilldown-deep — rincian LEBIH DALAM untuk satu sel pivot
     * (Pekerjaan PB7-I × PB712-II × Klasifikasi tertentu) yang diklik di popup.
     */
    public function drilldownDeep(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $request->validate([
            'type' => 'required|in:LM14,lm14',
            'batch' => 'required',
            'unit' => 'required',
            'kode' => 'required',
            'column' => 'required',
            'komoditi' => 'nullable|in:KS,KR,ks,kr',
            'pb7' => 'nullable|string',
            'pb712' => 'nullable|string',
            'klasifikasi' => 'nullable|string',
        ]);

        $type = strtoupper((string) $request->type);
        $batch = $this->findBatch((string) $request->batch);
        $komoditi = $request->filled('komoditi') ? strtoupper((string) $request->komoditi) : null;
        // ALL = tanpa filter plant_code (semua unit komoditi).
        $unit = $this->isAllUnits($request) ? null : $this->findUnit((string) $request->unit);

        $this->checkBatchAccess($batch);

        $template = LmTemplateRow::query()
            ->where('report_type', $type)
            ->where('komoditi', $komoditi)
            ->where(fn ($query) => $query
                ->where('kode', $request->kode)
                ->orWhere('urutan', is_numeric($request->kode) ? (int) $request->kode : 0))
            ->orderBy('urutan')
            ->first();

        $scope = $this->columnPeriodScope((string) $request->column);

        $rows = [];
        if ($template !== null && $scope !== null) {
            $pb7 = $request->filled('pb7') ? (string) $request->pb7 : null;
            $pb712 = $request->filled('pb712') ? (string) $request->pb712 : null;
            $klasifikasi = $request->filled('klasifikasi') ? (string) $request->klasifikasi : null;

            foreach ($this->contributingDetailTemplates($template, $type, $komoditi) as $detail) {
                foreach ($this->rawDeepRows($detail, $batch, $unit, $komoditi, $scope, $pb7, $pb712, $klasifikasi) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return response()->json([
            'success' => true,
            'context' => [
                'pb7' => $request->pb7,
                'pb712' => $request->pb712,
                'klasifikasi' => $request->klasifikasi,
                'column_label' => $this->columnLabel($type, (string) $request->column),
            ],
            'detail' => $this->buildRawDetail($rows),
        ]);
    }

    /**
     * Susun baris rincian dalam APA ADANYA (data mentah, bukan summary): tiap baris
     * sumber ditampilkan utuh, dikelompokkan per tabel asal (DB WBS / DB OHC) lengkap
     * dengan definisi kolom file asli, subtotal nilai per blok, dan grand total.
     *
     * @param  array<int, object>  $rows
     * @return array<string, mixed>
     */
    private function buildRawDetail(array $rows): array
    {
        // Kelompokkan baris per tabel sumber, pertahankan urutan kemunculan.
        $byTable = [];
        foreach ($rows as $row) {
            $byTable[(string) $row->_table][] = $row;
        }

        $sections = [];
        $grandTotal = 0.0;
        $rowCount = 0;

        // Urut blok: DB WBS dulu, lalu DB OHC, sisanya menyusul.
        $order = ['db_wbs_raw', 'db_ohc'];
        $tables = array_values(array_unique(array_merge(
            array_values(array_filter($order, fn ($t) => isset($byTable[$t]))),
            array_keys($byTable),
        )));

        foreach ($tables as $table) {
            if (empty($byTable[$table])) {
                continue;
            }

            $meta = $this->rawTableMeta($table);
            $valueField = $meta['value_field'];
            $qtyField = $meta['qty_field'];

            $items = [];
            $subtotal = 0.0;
            $qtySubtotal = 0.0;
            foreach ($byTable[$table] as $row) {
                $arr = (array) $row;
                unset($arr['_table'], $arr['id'], $arr['batch_id'], $arr['plant_code']);
                $items[] = $arr;
                $subtotal += (float) ($row->{$valueField} ?? 0);
                $qtySubtotal += (float) ($row->{$qtyField} ?? 0);
            }

            $grandTotal += $subtotal;
            $rowCount += count($items);

            $sections[] = [
                'table' => $table,
                'label' => $meta['label'],
                'value_field' => $valueField,
                'qty_field' => $qtyField,
                'columns' => $meta['columns'],
                'rows' => $items,
                'subtotal' => $subtotal,
                'qty_subtotal' => $qtySubtotal,
                'row_count' => count($items),
            ];
        }

        return [
            'sections' => $sections,
            'grand_total' => $grandTotal,
            'row_count' => $rowCount,
        ];
    }

    /**
     * Definisi kolom file asli untuk satu tabel sumber mentah: label kolom (urut
     * sesuai file) + flag numerik + field nilai (uang) untuk subtotal.
     *
     * @return array{label: string, value_field: string, qty_field: string, columns: array<int, array{field: string, label: string, numeric: bool}>}
     */
    private function rawTableMeta(string $table): array
    {
        if ($table === 'db_ohc') {
            $label = 'DB OHC';
            $labels = self::OHC_RAW_LABELS;
        } elseif ($table === 'db_wbs_tahun_lalu') {
            $label = 'DB WBS (Thn Lalu)';
            $labels = self::WBS_RAW_LABELS;
        } else {
            $label = 'DB WBS';
            $labels = self::WBS_RAW_LABELS;
        }

        $numeric = self::RAW_NUMERIC_FIELDS[$table] ?? [];
        $columns = [];
        foreach ($labels as $field => $colLabel) {
            $columns[] = [
                'field' => $field,
                'label' => $colLabel,
                'numeric' => in_array($field, $numeric, true),
            ];
        }

        return [
            'label' => $label,
            'value_field' => self::RAW_VALUE_FIELD[$table] ?? 'value',
            'qty_field' => self::RAW_QTY_FIELD[$table] ?? 'qty',
            'columns' => $columns,
        ];
    }

    /**
     * Build metadata untuk response report.
     */
    private function buildMeta(Batch $batch, RefUnit $unit, string $reportType, ?string $komoditi = null): array
    {
        $kpiHari = $this->calculateKpiHari($batch);

        return [
            'report_type' => $reportType,
            'unit' => [
                'code' => $unit->code,
                'name' => $unit->name,
                'type' => $unit->type,
                'komoditi' => $komoditi ?? $unit->komoditi,
            ],
            'batch' => [
                'id' => $batch->id,
                'code' => $batch->code,
                'year' => $batch->year,
                'month' => $batch->month,
                'period' => $batch->month,
                'status' => $batch->status,
                'processed_at' => $batch->processed_at?->format('Y-m-d H:i:s'),
            ],
            'kpi_hari' => $kpiHari,
        ];
    }

    /**
     * Hitung KPI hari: jumlah hari bulan, hari dijalani, sisa hari.
     */
    private function calculateKpiHari(Batch $batch): array
    {
        $year = $batch->year;
        $month = $batch->month;

        // Jumlah hari dalam bulan
        $jumlahHari = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        // Hari dijalani = berdasar tanggal processed_at
        $hariDijalani = 0;
        if ($batch->processed_at) {
            $processedDate = $batch->processed_at;
            if ($processedDate->year == $year && $processedDate->month == $month) {
                $hariDijalani = $processedDate->day;
            } else {
                // Jika processed_at di luar bulan batch, anggap semua hari sudah dijalani
                $hariDijalani = $jumlahHari;
            }
        }

        $sisaHari = max(0, $jumlahHari - $hariDijalani);

        return [
            'jumlah_hari' => $jumlahHari,
            'hari_dijalani' => $hariDijalani,
            'sisa_hari' => $sisaHari,
        ];
    }

    /**
     * Check batch access untuk Viewer (hanya final/locked).
     */
    /**
     * Find batch by ID or code.
     */
    private function findBatch(string $batchId): Batch
    {
        return Batch::query()
            ->where('id', is_numeric($batchId) ? (int) $batchId : 0)
            ->orWhere('code', $batchId)
            ->firstOrFail();
    }

    /**
     * Find unit by ID or code.
     */
    private function findUnit(string $unitId): RefUnit
    {
        return RefUnit::query()
            ->where('id', is_numeric($unitId) ? (int) $unitId : 0)
            ->orWhere('code', $unitId)
            ->firstOrFail();
    }

    /**
     * Apakah filter unit bernilai "ALL" (laporan konsolidasi Semua Unit).
     */
    private function isAllUnits(Request $request): bool
    {
        return strtoupper((string) $request->input('unit')) === 'ALL';
    }

    /**
     * Unit semu untuk meta laporan konsolidasi "Semua Unit" (tidak disimpan ke DB).
     */
    private function allUnitsPlaceholder(string $type, ?string $komoditi): RefUnit
    {
        $unit = new RefUnit();
        $unit->id = 0;
        $unit->code = 'ALL';
        $unit->name = 'Semua Unit';
        $unit->type = $type;
        $unit->komoditi = $komoditi;

        return $unit;
    }

    /**
     * Rasio aman (penyebut 0 → 0), selaras Lm14Service::percent untuk capaian gabungan.
     */
    private function percent(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        $value = round(($numerator / $denominator) * 100, 2);

        // Selaras dengan Lm14Service::percent(): batasi ke rentang kolom capaian
        // decimal(10,2) agar penyebut mendekati nol tak menghasilkan rasio ekstrem.
        return max(-99999999.99, min(99999999.99, $value));
    }

    /**
     * Agregasi LM14 "Semua Unit": SUM tiap kolom nilai antar seluruh unit komoditi ini
     * (per baris template), lalu hitung ulang kolom Capaian (%) dari nilai gabungan.
     * Bentuk baris dibuat menyerupai hasil join report+template agar bisa langsung
     * dilewatkan ke formatLm14Row().
     */
    private function aggregateLm14Rows(Batch $batch, string $komoditi): \Illuminate\Support\Collection
    {
        $sumCols = [
            'real_bulan_ini', 'real_bulan_lalu', 'real_tahun_lalu', 'rko', 'rkap',
            'real_sd_bulan_ini', 'real_sd_tahunlalu', 'rko_sd', 'rkap_sd',
        ];

        $selects = [
            'lm_template_row.urutan', 'lm_template_row.kode', 'lm_template_row.uraian',
            'lm_template_row.row_type', 'lm_template_row.indent',
        ];
        foreach ($sumCols as $col) {
            $selects[] = DB::raw('SUM(report_lm14.'.$col.') as '.$col);
        }

        $rows = DB::table('report_lm14')
            ->join('lm_template_row', 'report_lm14.template_id', '=', 'lm_template_row.id')
            ->where('report_lm14.batch_id', $batch->id)
            ->where('report_lm14.komoditi', $komoditi)
            ->where('lm_template_row.komoditi', $komoditi)
            ->groupBy(
                'lm_template_row.id', 'lm_template_row.urutan', 'lm_template_row.kode',
                'lm_template_row.uraian', 'lm_template_row.row_type', 'lm_template_row.indent'
            )
            ->orderBy('lm_template_row.urutan')
            ->select($selects)
            ->get();

        return $rows->map(function ($row) {
            $row->cap_bi_lalu = $this->percent((float) $row->real_bulan_ini, (float) $row->real_bulan_lalu);
            $row->cap_bi_thnlalu = $this->percent((float) $row->real_bulan_ini, (float) $row->real_tahun_lalu);
            $row->cap_bi_rko = $this->percent((float) $row->real_bulan_ini, (float) $row->rko);
            $row->cap_bi_rkap = $this->percent((float) $row->real_bulan_ini, (float) $row->rkap);
            $row->cap_sd_thnlalu = $this->percent((float) $row->real_sd_bulan_ini, (float) $row->real_sd_tahunlalu);
            $row->cap_sd_rko = $this->percent((float) $row->real_sd_bulan_ini, (float) $row->rko_sd);
            $row->cap_sd_rkap = $this->percent((float) $row->real_sd_bulan_ini, (float) $row->rkap_sd);

            return $row;
        });
    }

    /**
     * Agregasi LM13 "Semua Unit": SUM tiap kolom nilai antar seluruh unit komoditi ini
     * per baris template DAN per blok (OLAH_JUAL/OLAH/JUAL). LM13 tidak punya kolom
     * capaian sehingga cukup penjumlahan langsung.
     */
    private function aggregateLm13Rows(Batch $batch, string $komoditi): \Illuminate\Support\Collection
    {
        $sumCols = [
            'bi_real_thn_lalu', 'bi_real_thn_ini', 'bi_rko_tw', 'bi_rkap',
            'sd_real_thn_lalu', 'sd_real_thn_ini', 'sd_rko_tw', 'sd_rkap',
        ];

        $selects = [
            'lm_template_row.urutan', 'lm_template_row.kode', 'lm_template_row.uraian',
            'lm_template_row.row_type', 'lm_template_row.indent', 'report_lm13.blok',
        ];
        foreach ($sumCols as $col) {
            $selects[] = DB::raw('SUM(report_lm13.'.$col.') as '.$col);
        }

        return DB::table('report_lm13')
            ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
            ->where('report_lm13.batch_id', $batch->id)
            ->where('report_lm13.komoditi', $komoditi)
            ->where('lm_template_row.komoditi', $komoditi)
            ->groupBy(
                'lm_template_row.id', 'lm_template_row.urutan', 'lm_template_row.kode',
                'lm_template_row.uraian', 'lm_template_row.row_type', 'lm_template_row.indent',
                'report_lm13.blok'
            )
            ->orderBy('report_lm13.blok', 'asc')
            ->orderBy('lm_template_row.urutan')
            ->select($selects)
            ->get();
    }

    /**
     * Sisipkan baris "Jumlah" untuk seksi A "Saldo Awal" pada LM13 (urutan 2,3,4 =
     * Kebun Inti + Plasma + Pihak III), tepat di bawah "- Pihak III". Baris ini
     * TURUNAN dari baris detail yang sudah ada per blok (tidak mengubah template
     * maupun mesin hitung). urutan 4.5 dipakai agar tampil di antara baris 4 dan 5;
     * frontend mengelompokkan per urutan & memetakan sel per blok, jadi aman.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function withSaldoAwalJumlah(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $valueCols = [
            'bi_real_thn_lalu', 'bi_real_thn_ini', 'bi_rko_tw', 'bi_rkap',
            'sd_real_thn_lalu', 'sd_real_thn_ini', 'sd_rko_tw', 'sd_rkap',
        ];

        // Jumlahkan baris detail seksi A (urutan 2,3,4) per blok.
        $byBlok = [];
        foreach ($rows as $row) {
            if (! in_array((int) $row->urutan, [2, 3, 4], true)) {
                continue;
            }
            $byBlok[$row->blok] ??= array_fill_keys($valueCols, 0.0);
            foreach ($valueCols as $col) {
                $byBlok[$row->blok][$col] += (float) ($row->{$col} ?? 0);
            }
        }

        if ($byBlok === []) {
            return $rows;
        }

        $indent = $rows->first(fn ($r) => (int) $r->urutan === 4)->indent ?? 0;

        foreach ($byBlok as $blok => $sums) {
            $rows->push((object) array_merge([
                'urutan' => 4.5,
                'kode' => null,
                'uraian' => 'Jumlah',
                'row_type' => 'subtotal',
                'indent' => $indent,
                'blok' => $blok,
            ], $sums));
        }

        // Pertahankan urutan tampilan (blok asc, urutan asc) seperti query asal.
        return $rows->sortBy([
            ['blok', 'asc'],
            ['urutan', 'asc'],
        ])->values();
    }

    /**
     * Format LM14 row untuk response.
     * Mapping dari nama kolom SQL ke nama API response.
     */
    private function formatLm14Row($row): array
    {
        return $this->withCellMetadata([
            'urutan' => $row->urutan,
            'kode' => $row->kode,
            'uraian' => $row->uraian,
            'row_type' => $row->row_type,
            'indent' => $row->indent ?? 0,
            // Mapping dari schema SQL ke API response
            'real_bulan_lalu' => (float) $row->real_bulan_lalu,
            'real_thn_lalu' => (float) $row->real_tahun_lalu,
            'bi_jumlah' => (float) $row->real_bulan_ini,
            'bi_rko' => (float) $row->rko,
            'bi_rkap' => (float) $row->rkap,
            'sd_jumlah' => (float) $row->real_sd_bulan_ini,
            'sd_real_thn_lalu' => (float) $row->real_sd_tahunlalu,
            'sd_rko' => (float) $row->rko_sd,
            'sd_rkap' => (float) $row->rkap_sd,
            'cap_bi_lalu' => (float) $row->cap_bi_lalu,
            'cap_bi_thnlalu' => (float) $row->cap_bi_thnlalu,
            'cap_bi_rko' => (float) $row->cap_bi_rko,
            'cap_bi_rkap' => (float) $row->cap_bi_rkap,
            'cap_sd_lalu' => (float) ($row->cap_sd_thnlalu ?? 0),
            'cap_sd_rko' => (float) $row->cap_sd_rko,
            'cap_sd_rkap' => (float) $row->cap_sd_rkap,
        ], $row->kode ?? (string) $row->urutan, [
            'real_bulan_lalu',
            'real_thn_lalu',
            'bi_jumlah',
            'bi_rko',
            'bi_rkap',
            'sd_jumlah',
            'sd_real_thn_lalu',
            'sd_rko',
            'sd_rkap',
            'cap_bi_lalu',
            'cap_bi_thnlalu',
            'cap_bi_rko',
            'cap_bi_rkap',
            'cap_sd_lalu',
            'cap_sd_rko',
            'cap_sd_rkap',
        ]);
    }

    /**
     * Format LM13 row untuk response.
     * Mapping dari nama kolom SQL ke nama API response.
     */
    private function formatLm13Row($row): array
    {
        return $this->withCellMetadata([
            'urutan' => $row->urutan,
            'kode' => $row->kode,
            'uraian' => $row->uraian,
            'row_type' => $row->row_type,
            'indent' => $row->indent ?? 0,
            'block' => $row->blok, // Mapping dari 'blok' SQL ke 'block' API
            // Mapping dari schema SQL ke API response
            'real_thn_lalu' => (float) $row->bi_real_thn_lalu, // atau sd_real_thn_lalu?
            'bi_jumlah' => (float) $row->bi_real_thn_ini,
            'bi_rko' => (float) $row->bi_rko_tw,
            'bi_rkap' => (float) $row->bi_rkap,
            'sd_real_thn_lalu' => (float) $row->sd_real_thn_lalu,
            'sd_jumlah' => (float) $row->sd_real_thn_ini,
            'sd_rko' => (float) $row->sd_rko_tw,
            'sd_rkap' => (float) $row->sd_rkap,
        ], $row->kode ?? (string) $row->urutan, [
            'real_thn_lalu',
            'bi_jumlah',
            'bi_rko',
            'bi_rkap',
            'sd_real_thn_lalu',
            'sd_jumlah',
            'sd_rko',
            'sd_rkap',
        ]);
    }

    /**
     * Format LM16 row untuk response.
     */
    private function formatLm16Row($row): array
    {
        return $this->withCellMetadata([
            'urutan' => $row->urutan,
            'kode' => $row->kode,
            'uraian' => $row->uraian,
            'row_type' => $row->row_type,
            'indent' => $row->indent ?? 0,
            'real_bln_lalu' => (float) $row->real_bln_lalu,
            'bi_olah' => (float) $row->bi_olah,
            'bi_kso' => (float) $row->bi_kso,
            'bi_jumlah' => (float) $row->bi_jumlah,
            'bi_rko' => (float) $row->bi_rko,
            'bi_rkap' => (float) $row->bi_rkap,
            'sd_olah' => (float) $row->sd_olah,
            'sd_kso' => (float) $row->sd_kso,
            'sd_jumlah' => (float) $row->sd_jumlah,
            'sd_rko' => (float) $row->sd_rko,
            'sd_rkap' => (float) $row->sd_rkap,
            'cap_bi_lalu' => (float) $row->cap_bi_lalu,
            'cap_bi_rkap' => (float) $row->cap_bi_rkap,
            'cap_bi_sd' => (float) $row->cap_bi_sd,
            'cap_sd_rkap' => (float) $row->cap_sd_rkap,
            'rp_kg_tbs' => (float) $row->rp_kg_tbs,
            'rp_kg_mi' => (float) $row->rp_kg_mi,
        ], $row->kode ?? (string) $row->urutan, [
            'real_bln_lalu',
            'bi_olah',
            'bi_kso',
            'bi_jumlah',
            'bi_rko',
            'bi_rkap',
            'sd_olah',
            'sd_kso',
            'sd_jumlah',
            'sd_rko',
            'sd_rkap',
            'cap_bi_lalu',
            'cap_bi_rkap',
            'cap_bi_sd',
            'cap_sd_rkap',
            'rp_kg_tbs',
            'rp_kg_mi',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $valueKeys
     * @return array<string, mixed>
     */
    private function withCellMetadata(array $row, ?string $kodeBaris, array $valueKeys): array
    {
        $kode = $kodeBaris ?: (string) $row['urutan'];
        $cells = [];

        foreach ($valueKeys as $key) {
            $cells[$key] = [
                'value' => $row[$key] ?? 0,
                'drilldown' => [
                    'kode_baris' => $kode,
                    'column_key' => $key,
                ],
            ];
        }

        return [
            ...$row,
            'cells' => $cells,
        ];
    }

    /**
     * Column definitions untuk LM14 (grouped header).
     */
    private function getLm14Columns(): array
    {
        return [
            ['key' => 'kode', 'title' => 'Kode', 'frozen' => true, 'group' => null],
            ['key' => 'uraian', 'title' => 'Uraian', 'frozen' => true, 'group' => null],
            ['key' => 'real_thn_lalu', 'title' => 'Real Thn Lalu', 'group' => 'Realisasi'],
            ['key' => 'bi_jumlah', 'title' => 'Jumlah', 'group' => 'Bulan Ini'],
            ['key' => 'bi_rko', 'title' => 'RKO', 'group' => 'Bulan Ini'],
            ['key' => 'bi_rkap', 'title' => 'RKAP', 'group' => 'Bulan Ini'],
            ['key' => 'sd_jumlah', 'title' => 'Jumlah', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_rko', 'title' => 'RKO', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_rkap', 'title' => 'RKAP', 'group' => 's.d Bulan Ini'],
            ['key' => 'cap_bi_lalu', 'title' => 'BI/Lalu', 'group' => 'Capaian (%)'],
            ['key' => 'cap_bi_rko', 'title' => 'BI/RKO', 'group' => 'Capaian (%)'],
            ['key' => 'cap_bi_rkap', 'title' => 'BI/RKAP', 'group' => 'Capaian (%)'],
            ['key' => 'cap_sd_lalu', 'title' => 'SD/Lalu', 'group' => 'Capaian (%)'],
            ['key' => 'cap_sd_rko', 'title' => 'SD/RKO', 'group' => 'Capaian (%)'],
            ['key' => 'cap_sd_rkap', 'title' => 'SD/RKAP', 'group' => 'Capaian (%)'],
        ];
    }

    /**
     * Column definitions untuk LM13 (grouped header, 3 blok).
     */
    private function getLm13Columns(): array
    {
        return [
            ['key' => 'kode', 'title' => 'Kode', 'frozen' => true, 'group' => null, 'block' => null],
            ['key' => 'uraian', 'title' => 'Uraian', 'frozen' => true, 'group' => null, 'block' => null],
            ['key' => 'block', 'title' => 'Blok', 'group' => null],
            ['key' => 'real_thn_lalu', 'title' => 'Real Thn Lalu', 'group' => 'Realisasi'],
            ['key' => 'bi_jumlah', 'title' => 'Bulan Ini', 'group' => 'Realisasi'],
            ['key' => 'bi_rko', 'title' => 'RKO', 'group' => 'Budget'],
            ['key' => 'bi_rkap', 'title' => 'RKAP', 'group' => 'Budget'],
            ['key' => 'sd_jumlah', 'title' => 's.d BI', 'group' => 'Realisasi'],
            ['key' => 'sd_rko', 'title' => 'RKO', 'group' => 'Budget'],
            ['key' => 'sd_rkap', 'title' => 'RKAP', 'group' => 'Budget'],
        ];
    }

    /**
     * Column definitions untuk LM16 (grouped header, Olah/KSO/Jumlah).
     */
    private function getLm16Columns(RefUnit $unit): array
    {
        $isOlah = $unit->olah_status === 'Olah';

        return [
            ['key' => 'kode', 'title' => 'Kode', 'frozen' => true, 'group' => null],
            ['key' => 'uraian', 'title' => 'Uraian', 'frozen' => true, 'group' => null],
            ['key' => 'real_bln_lalu', 'title' => 'Real Bln Lalu', 'group' => null],
            // Bulan Ini
            ['key' => 'bi_olah', 'title' => $isOlah ? 'Olah' : 'Tidak Olah', 'group' => 'Bulan Ini'],
            ['key' => 'bi_kso', 'title' => 'KSO', 'group' => 'Bulan Ini'],
            ['key' => 'bi_jumlah', 'title' => 'Jumlah', 'group' => 'Bulan Ini'],
            ['key' => 'bi_rko', 'title' => 'RKO', 'group' => 'Bulan Ini'],
            ['key' => 'bi_rkap', 'title' => 'RKAP', 'group' => 'Bulan Ini'],
            // s.d Bulan Ini
            ['key' => 'sd_olah', 'title' => $isOlah ? 'Olah' : 'Tidak Olah', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_kso', 'title' => 'KSO', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_jumlah', 'title' => 'Jumlah', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_rko', 'title' => 'RKO', 'group' => 's.d Bulan Ini'],
            ['key' => 'sd_rkap', 'title' => 'RKAP', 'group' => 's.d Bulan Ini'],
            // Capaian
            ['key' => 'cap_bi_lalu', 'title' => 'BI/Lalu', 'group' => 'Capaian (%)'],
            ['key' => 'cap_bi_rkap', 'title' => 'BI/RKAP', 'group' => 'Capaian (%)'],
            ['key' => 'cap_bi_sd', 'title' => 'BI/SD', 'group' => 'Capaian (%)'],
            ['key' => 'cap_sd_rkap', 'title' => 'SD/RKAP', 'group' => 'Capaian (%)'],
            // Harga Pokok
            ['key' => 'rp_kg_tbs', 'title' => 'Rp/Kg TBS', 'group' => 'Harga Pokok'],
            ['key' => 'rp_kg_mi', 'title' => 'Rp/Kg M+I', 'group' => 'Harga Pokok'],
        ];
    }

    /** Urutan kanonik kolom klasifikasi pada pivot rincian sumber. */
    private const KLASIFIKASI_ORDER = ['1. Gaji', '2. SPK', '3. Bahan', '4. EAP', '5. Depresiasi', '6.Lain-Lain'];

    /** Placeholder untuk nilai kosong pada pivot (dipakai pivot & filter rincian dalam). */
    private const PIVOT_BLANK = '(Tanpa Keterangan)';

    private const PIVOT_BLANK_KLAS = '(Tanpa Klasifikasi)';

    /**
     * Kolom mentah db_wbs_raw (field DB => judul kolom file asli), urut sesuai file
     * sumber DB WBS (A..AV). Dipakai menampilkan rincian "data apa adanya" per baris.
     */
    private const WBS_RAW_LABELS = [
        'company_code' => 'Company Code', 'plant' => 'Plant', 'plant_desc' => 'Desc.',
        'divisi_afdeling' => 'Divisi/Afdeling', 'blok' => 'Blok', 'status_blok' => 'Status Blok',
        'tahun_tanam' => 'Tahun Tanam', 'komoditi' => 'Komoditi', 'period' => 'Period',
        'project' => 'Project', 'wbs' => 'WBS', 'wbs_desc' => 'WBS Desc.', 'fase' => 'Fase.',
        'group_aktifitas' => 'Group Aktifitas', 'group_desc' => 'Group Desc', 'aktifitas' => 'Aktifitas',
        'job_name' => 'Job Name', 'hierarchy_area' => 'Hierarchy Area', 'cost_center' => 'Cost Center',
        'cc_desc' => 'CC Desc.', 'partner_cctr' => 'Partner-CCtr', 'partner_cctr_desc' => 'Partner-CCtr Desc.',
        'cost_element' => 'Cost Element', 'cost_element_desc' => 'Cost Element Desc', 'value' => 'Value',
        'currency' => 'Currency', 'material' => 'Material', 'mat_desc' => 'Mat. Desc.', 'qty' => 'Qty',
        'uom' => 'UoM', 'object_num' => 'Object Num.', 'object_type' => 'Object Type',
        'profit_center' => 'Profit Center', 'value_type' => 'Value Type', 'reference_procedure' => 'Reference Procedure',
        'order_no' => 'Order', 'order_type' => 'Order Type', 'order_category' => 'Order Category',
        'order_desc' => 'Order Desc.', 'hectare_planted' => 'Hectare Planted',
        'co_business_transaction' => 'CO Business Transaction', 'mapping_cogm' => 'Mapping COGM',
        'klasifikasi' => 'Klasifikasi', 'kode' => 'Kode', 'pekerjaan_pb712_ii' => 'Pekerjaan PB712-II',
        'pekerjaan_pb7_i' => 'Pekerjaan PB7-I', 'source' => 'Source', 'keterangan' => 'Keterangan',
    ];

    /**
     * Kolom mentah db_ohc (field DB => judul kolom file asli), urut sesuai file
     * sumber DB OHC (A..AR).
     */
    private const OHC_RAW_LABELS = [
        'cost_center' => 'Cost Center', 'co_object_name' => 'CO Object Name',
        'business_transaction' => 'Business Transaction', 'document_number' => 'Document Number',
        'ref_document_number' => 'Ref. document number', 'cost_element' => 'Cost Element',
        'cost_element_name' => 'Cost element name', 'period' => 'Period', 'posting_date' => 'Posting Date',
        'value_obj_crcy' => 'Value in Obj. Crcy', 'total_quantity' => 'Total quantity',
        'posted_uom' => 'Posted unit of meas.', 'name' => 'Name', 'user_name' => 'User Name',
        'material' => 'Material', 'material_description' => 'Material Description',
        'reference_procedure' => 'Reference procedure', 'dr_cr_indicator' => 'Dr/Cr indicator',
        'reference_key' => 'Reference Key', 'partner_object_class' => 'Partner Object Class',
        'object_type' => 'Object Type', 'partner_object_name' => 'Partner object name',
        'partner_object_type' => 'Partner Object Type', 'offsetting_account' => 'Offsetting Account',
        'name_offsetting_account' => 'Name of offsetting account',
        'name_offsetting_account_2' => 'Name of offsetting account2',
        'document_header_text' => 'Document Header Text', 'partner_object' => 'Partner Object',
        'partner_object_type3' => 'Partner object type3', 'partner_cctr' => 'Partner-CCtr',
        'source_object' => 'Source Object', 'source_object_name' => 'Source object name',
        'origin_obj_type' => 'Origin-obj. type', 'source_object_type' => 'Source object type',
        'cost_element_descr' => 'Cost element descr.', 'plant' => 'Plant', 'lock' => 'lock',
        'kode' => 'Kode', 'pekerjaan_pb712_ii' => 'Pekerjaan PB712-II', 'klasifikasi' => 'Klasifikasi',
        'pekerjaan_pb7_i' => 'Pekerjaan PB7-I', 'komoditi' => 'Komoditi', 'unit_kerja' => 'Unit Kerja',
        'pekerjaan_pb712_iii' => 'Pekerjaan PB712-III',
    ];

    /** Field nilai (uang) & field numerik per tabel sumber mentah. */
    private const RAW_VALUE_FIELD = ['db_wbs_raw' => 'value', 'db_ohc' => 'value_obj_crcy', 'db_wbs_tahun_lalu' => 'value'];

    /** Field kuantitas (fisik) per tabel sumber mentah — dipakai subtotal kolom Qty. */
    private const RAW_QTY_FIELD = ['db_wbs_raw' => 'qty', 'db_ohc' => 'total_quantity', 'db_wbs_tahun_lalu' => 'qty'];

    private const RAW_NUMERIC_FIELDS = [
        'db_wbs_raw' => ['period', 'value', 'qty', 'hectare_planted'],
        'db_ohc' => ['period', 'value_obj_crcy', 'total_quantity'],
        'db_wbs_tahun_lalu' => ['period', 'value', 'qty', 'hectare_planted'],
    ];

    /** Kolom detail sumber RKO/RKAP (budget_source) — field => judul, urut tampil. */
    private const BUDGET_SOURCE_LABELS = [
        'source' => 'Sumber', 'kode' => 'Kode', 'period' => 'Period',
        'object_name' => 'Pekerjaan', 'cost_element' => 'Cost Element',
        'cost_element_desc' => 'Cost Element Desc', 'klasifikasi' => 'Klasifikasi',
        'fisik' => 'Fisik', 'nilai' => 'Nilai',
    ];

    /** Kolom numerik pada detail sumber RKO/RKAP. */
    private const BUDGET_SOURCE_NUMERIC = ['period', 'fisik', 'nilai'];

    /** Kolom RKO/RKAP LM14 yang detailnya berasal dari budget_source. */
    private const BUDGET_COLUMNS = ['bi_rko', 'bi_rkap', 'sd_rko', 'sd_rkap'];

    /**
     * Label kolom yang diklik (untuk judul popup rincian).
     */
    private function columnLabel(string $type, string $columnKey): string
    {
        $labels = [
            'bi_jumlah' => 'Real Bulan Ini',
            'sd_jumlah' => 'Real s.d Bulan Ini',
            'real_bulan_lalu' => 'Real Bulan Lalu',
            'real_thn_lalu' => 'Real Tahun Lalu',
            'sd_real_thn_lalu' => 'Real s.d Tahun Lalu',
            'bi_rko' => 'RKO Bulan Ini',
            'bi_rkap' => 'RKAP Bulan Ini',
            'sd_rko' => 'RKO s.d',
            'sd_rkap' => 'RKAP s.d',
        ];

        return $labels[$columnKey] ?? $columnKey;
    }

    /**
     * Tentukan lingkup periode untuk kolom realisasi yang BERSUMBER dari tabel mentah.
     * Mengembalikan null untuk kolom non-sumber (anggaran, capaian, tahun lalu).
     */
    private function columnPeriodScope(string $columnKey): ?string
    {
        return match ($columnKey) {
            'bi_jumlah' => 'bi',
            'sd_jumlah' => 'sd',
            'real_bulan_lalu' => 'lalu',
            'real_thn_lalu' => 'tl_bi',     // bulan ini tahun lalu (db_wbs_tahun_lalu)
            'sd_real_thn_lalu' => 'tl_sd',  // s.d bulan ini tahun lalu
            default => null,
        };
    }

    /**
     * Pivot rincian sumber sel yang diklik: baris mentah penyusun nilai sel
     * (db_wbs_raw untuk WBS, db_ohc untuk BTL) dikelompokkan per
     * Pekerjaan PB7-I × PB712-II dan dipivot per klasifikasi. Grand total pivot
     * sama dengan nilai sel pada laporan.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Detail sumber RKO/RKAP per-baris (budget_source) untuk sel yang diklik —
     * LANGSUNG (tanpa pivot). Mengembalikan struktur sama dengan buildRawDetail
     * (sections + rows + subtotal + grand_total) sehingga frontend memakai renderer
     * "rincian lebih dalam" yang sama. Null bila kolom bukan RKO/RKAP LM14.
     *
     * RKO=RKAP (nilai sama), tetapi kolom "bulan ini" (bi_*) vs "s.d. bulan ini" (sd_*)
     * difilter per periode: bi_* = baris periode bulan ini; sd_* = baris periode 1..ini.
     * Baris periode NULL (anggaran tahunan) ikut di kedua kolom. Grand total = nilai sel.
     *
     * @return array<string, mixed>|null
     */
    private function budgetSourceDetail(string $type, Batch $batch, ?RefUnit $unit, ?string $komoditi, ?LmTemplateRow $template, string $columnKey): ?array
    {
        if ($type !== 'LM14' || ! $template || ! in_array($columnKey, self::BUDGET_COLUMNS, true)) {
            return null;
        }

        // Kumpulkan kode baris detail penyusun sel (baris detail = dirinya; subtotal/total
        // = ekspansi formula ke baris detail) — sama dengan dasar pivot realisasi.
        $kodes = [];
        foreach ($this->contributingDetailTemplates($template, $type, $komoditi) as $detail) {
            $kode = (string) $detail->kode;
            if ($kode !== '') {
                $kodes[$kode] = true;
            }
        }
        if ($kodes === []) {
            return ['sections' => [], 'grand_total' => 0.0, 'row_count' => 0];
        }

        $query = DB::table('budget_source')
            ->where('year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('report_type', 'LM14')
            ->whereIn('kode', array_keys($kodes));

        // Filter periode selaras kolom: sd_* (s.d. bulan ini) = akumulasi 1..bulan;
        // bi_* (bulan ini) = bulan batch saja. Baris periode NULL = anggaran tahunan (ikut).
        $cumulative = str_starts_with($columnKey, 'sd_');
        $query->where(function ($inner) use ($batch, $cumulative): void {
            $inner->whereNull('period');
            $cumulative
                ? $inner->orWhere('period', '<=', $batch->month)
                : $inner->orWhere('period', '=', $batch->month);
        });

        // "Semua Unit" (unit null) = tanpa filter plant_code → gabung seluruh kebun komoditi ini.
        if ($unit !== null) {
            $query->where('plant_code', $unit->code);
        }

        $rows = $query->orderBy('source')->orderBy('kode')->orderBy('id')->get();

        return $this->buildBudgetDetail($rows->all());
    }

    /**
     * Susun detail sumber RKO/RKAP per sumber (BKU/OHC) dengan subtotal & grand total,
     * meniru bentuk buildRawDetail agar konsumsi frontend identik.
     *
     * @param  array<int, object>  $rows
     * @return array<string, mixed>
     */
    private function buildBudgetDetail(array $rows): array
    {
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row->source][] = $row;
        }

        // Urut blok: BKU (WBS) dulu, lalu OHC (BTL), sisanya menyusul.
        $order = ['BKU', 'OHC'];
        $sources = array_values(array_unique(array_merge(
            array_values(array_filter($order, fn ($s) => isset($bySource[$s]))),
            array_keys($bySource),
        )));

        $columns = [];
        foreach (self::BUDGET_SOURCE_LABELS as $field => $label) {
            $columns[] = [
                'field' => $field,
                'label' => $label,
                'numeric' => in_array($field, self::BUDGET_SOURCE_NUMERIC, true),
            ];
        }

        $fields = array_keys(self::BUDGET_SOURCE_LABELS);
        $sections = [];
        $grandTotal = 0.0;
        $rowCount = 0;

        foreach ($sources as $src) {
            $items = [];
            $subtotal = 0.0;
            $qtySubtotal = 0.0;
            foreach ($bySource[$src] as $row) {
                $item = [];
                foreach ($fields as $field) {
                    $item[$field] = $row->{$field} ?? null;
                }
                $items[] = $item;
                $subtotal += (float) ($row->nilai ?? 0);
                $qtySubtotal += (float) ($row->fisik ?? 0);
            }

            $grandTotal += $subtotal;
            $rowCount += count($items);

            $sections[] = [
                'table' => 'budget_source_'.strtolower($src),
                'label' => $src === 'BKU' ? 'RKO/RKAP — BKU (WBS)'
                    : ($src === 'OHC' ? 'RKO/RKAP — OHC (BTL)' : 'RKO/RKAP — '.$src),
                'value_field' => 'nilai',
                'qty_field' => 'fisik',
                'columns' => $columns,
                'rows' => $items,
                'subtotal' => $subtotal,
                'qty_subtotal' => $qtySubtotal,
                'row_count' => count($items),
            ];
        }

        return [
            'sections' => $sections,
            'grand_total' => $grandTotal,
            'row_count' => $rowCount,
        ];
    }

    private function drilldownPivot(string $type, Batch $batch, ?RefUnit $unit, ?string $komoditi, ?LmTemplateRow $template, string $columnKey): ?array
    {
        if ($type !== 'LM14' || ! $template) {
            return null;
        }

        $scope = $this->columnPeriodScope($columnKey);
        if ($scope === null) {
            return null;
        }

        $details = $this->contributingDetailTemplates($template, $type, $komoditi);
        if ($details === []) {
            return null;
        }

        $rows = [];
        foreach ($details as $detail) {
            foreach ($this->rawBreakdownRows($detail, $batch, $unit, $komoditi, $scope) as $row) {
                $rows[] = $row;
            }
        }

        // Tanpa baris penyusun (mis. baris BTL tahun lalu yang sumbernya OHC dan belum
        // tersedia) → null agar UI menampilkan pesan, bukan pivot kosong.
        if ($rows === []) {
            return null;
        }

        return $this->buildPivot($rows);
    }

    /**
     * Daftar baris template bertipe detail yang menyusun sebuah sel. Untuk baris
     * detail = dirinya sendiri; untuk subtotal/total = ekspansi rekursif formula
     * (u{urutan}+u{urutan}...) hingga ke baris detail.
     *
     * @return array<int, LmTemplateRow>
     */
    private function contributingDetailTemplates(LmTemplateRow $template, string $type, ?string $komoditi): array
    {
        if ($template->row_type === 'detail') {
            return [$template];
        }

        $all = LmTemplateRow::query()
            ->where('report_type', $type)
            ->when($komoditi !== null, fn ($query) => $query->where('komoditi', $komoditi))
            ->get()
            ->keyBy('urutan');

        $details = [];
        $visit = function (LmTemplateRow $node) use (&$visit, $all, &$details): void {
            if ($node->row_type === 'detail') {
                $details[$node->urutan] = $node;

                return;
            }

            preg_match_all('/u(\d+)/i', (string) $node->formula, $matches);
            foreach ($matches[1] ?? [] as $urutan) {
                $child = $all->get((int) $urutan);
                if ($child !== null) {
                    $visit($child);
                }
            }
        };
        $visit($template);

        return array_values($details);
    }

    /**
     * Bangun query dasar tabel sumber untuk satu baris detail (tabel, kolom nilai,
     * filter kode/komoditi/plant, dan lingkup periode) — kriteria SAMA dgn Lm14Service.
     * Dipakai bersama oleh pivot rincian (level-1) dan rincian lebih dalam (level-2).
     *
     * @return array{query: \Illuminate\Database\Query\Builder, table: string, value: string}|null
     */
    private function rawSourceQuery(LmTemplateRow $detail, Batch $batch, ?RefUnit $unit, ?string $komoditi, string $scope): ?array
    {
        $source = $detail->source;
        $kode = (string) $detail->kode;
        if ($source === null || $kode === '') {
            return null;
        }

        // Kolom "Real Thn Lalu" / "s.d Thn Lalu": baris mentah dari db_wbs_tahun_lalu
        // (sisi Pengirim, tahun = tahun batch − 1). Hanya baris WBS yang punya data;
        // baris BTL (gaji staf/depresiasi/overhead) menunggu ekstrak OHC tahun lalu.
        if ($scope === 'tl_bi' || $scope === 'tl_sd') {
            if ($source !== 'WBS') {
                return null;
            }
            $table = 'db_wbs_tahun_lalu';
            $query = DB::table($table)
                ->where($table.'.aktifitas', $kode)
                ->where($table.'.komoditi', $komoditi)
                ->where($table.'.year', $batch->year - 1);
            if ($unit !== null) {
                $query->where($table.'.plant_code', $unit->code);
            }
            if ($scope === 'tl_bi') {
                $query->where($table.'.period', $batch->month);
            } else {
                $query->where($table.'.period', '<=', $batch->month);
            }

            return ['query' => $query, 'table' => $table, 'value' => 'value'];
        }

        $isStafGaji = $source === 'BTL' && $kode === '99-01';

        // Tentukan tabel, kolom nilai, dan kriteria kode (selaras Lm14Service).
        // Gaji staf SELALU dari db_ohc lock SP01/SR01 untuk semua kolom (termasuk
        // "bulan lalu") — aktifitas 99-01 di db_wbs_raw mencampur banyak klasifikasi
        // (Gaji+Lain-Lain+Depresiasi) sehingga bukan gaji staf.
        if ($source === 'WBS') {
            $table = 'db_wbs_raw';
            $valueColumn = 'value';
            $codeColumn = 'aktifitas';
            $codeValue = $kode;
        } elseif ($source === 'BTL') {
            $table = 'db_ohc';
            $valueColumn = 'value_obj_crcy';
            if ($isStafGaji) {
                $codeColumn = 'lock';
                $codeValue = strtoupper((string) $komoditi) === 'KR' ? 'SR01' : 'SP01'; // SP01=Sawit, SR01=Karet
            } elseif (str_starts_with($kode, '511')) {
                $codeColumn = 'cost_element';
                $codeValue = $kode;
            } else {
                $codeColumn = 'lock';
                $codeValue = $kode;
            }
        } else {
            return null;
        }

        $query = DB::table($table)
            ->where($table.'.'.$codeColumn, $codeValue)
            ->where($table.'.komoditi', $komoditi);

        // "Semua Unit" (unit null) = tanpa filter plant_code → gabung seluruh kebun komoditi ini.
        if ($unit !== null) {
            $query->where($table.'.plant_code', $unit->code);
        }

        // Lingkup periode sesuai kolom yang diklik (selaras Lm14Service).
        if ($scope === 'bi') {
            $query->where($table.'.batch_id', $batch->id)->where($table.'.period', $batch->month);
        } elseif ($scope === 'sd') {
            $query->join('batch', $table.'.batch_id', '=', 'batch.id')
                ->where('batch.year', $batch->year)
                ->where($table.'.period', '<=', $batch->month);
        } else { // lalu
            $query->join('batch', $table.'.batch_id', '=', 'batch.id')
                ->where('batch.year', $batch->year)
                ->where($table.'.period', $batch->month - 1);
        }

        return ['query' => $query, 'table' => $table, 'value' => $valueColumn];
    }

    /**
     * Baris mentah (pekerjaan_pb7_i, pekerjaan_pb712_ii, klasifikasi, total) untuk
     * satu baris detail.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rawBreakdownRows(LmTemplateRow $detail, Batch $batch, ?RefUnit $unit, ?string $komoditi, string $scope): \Illuminate\Support\Collection
    {
        $ctx = $this->rawSourceQuery($detail, $batch, $unit, $komoditi, $scope);
        if ($ctx === null) {
            return collect();
        }
        $table = $ctx['table'];

        return $ctx['query']
            ->groupBy($table.'.pekerjaan_pb7_i', $table.'.pekerjaan_pb712_ii', $table.'.klasifikasi')
            ->select(
                $table.'.pekerjaan_pb7_i as pb7',
                $table.'.pekerjaan_pb712_ii as pb712',
                $table.'.klasifikasi as klasifikasi',
                DB::raw('SUM('.$table.'.'.$ctx['value'].') as total'),
            )
            ->get();
    }

    /**
     * Rincian LEBIH DALAM untuk satu sel pivot (pb7 × pb712 × klasifikasi tertentu):
     * baris mentah APA ADANYA (tanpa agregasi/summary), seluruh kolom file sumber.
     * Tiap baris ditandai tabel asalnya (`_table`) agar bisa ditampilkan per-blok.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rawDeepRows(LmTemplateRow $detail, Batch $batch, ?RefUnit $unit, ?string $komoditi, string $scope, ?string $pb7, ?string $pb712, ?string $klasifikasi): \Illuminate\Support\Collection
    {
        $ctx = $this->rawSourceQuery($detail, $batch, $unit, $komoditi, $scope);
        if ($ctx === null) {
            return collect();
        }
        $table = $ctx['table'];
        $query = $ctx['query'];

        // Sempitkan ke sel pivot yang diklik.
        $this->applyGroupFilter($query, $table.'.pekerjaan_pb7_i', $pb7, self::PIVOT_BLANK);
        $this->applyGroupFilter($query, $table.'.pekerjaan_pb712_ii', $pb712, self::PIVOT_BLANK);
        $this->applyGroupFilter($query, $table.'.klasifikasi', $klasifikasi, self::PIVOT_BLANK_KLAS);

        // Ambil baris mentah apa adanya (seluruh kolom tabel sumber), urut sesuai id asli.
        return $query
            ->select($table.'.*')
            ->orderBy($table.'.id')
            ->get()
            ->map(function ($row) use ($table) {
                $row->_table = $table;

                return $row;
            });
    }

    /**
     * Terapkan filter kelompok pivot ke query rincian dalam. null = tanpa filter;
     * placeholder/kosong = cocokkan nilai NULL atau string kosong.
     */
    private function applyGroupFilter(\Illuminate\Database\Query\Builder $query, string $column, ?string $value, string $blankPlaceholder): void
    {
        if ($value === null) {
            return;
        }

        if ($value === $blankPlaceholder || $value === '') {
            $query->where(function ($q) use ($column) {
                $q->whereNull($column)->orWhere($column, '');
            });

            return;
        }

        $query->where($column, $value);
    }

    /**
     * Susun struktur pivot: grup per Pekerjaan PB7-I, baris per PB712-II, kolom per
     * klasifikasi, dengan subtotal grup dan grand total (baris & kolom).
     *
     * @param  array<int, object>  $rows
     * @return array<string, mixed>
     */
    private function buildPivot(array $rows): array
    {
        $blank = self::PIVOT_BLANK;
        $blankKlas = self::PIVOT_BLANK_KLAS;

        // Agregasi ke map: pb7 -> pb712 -> klasifikasi -> total.
        $agg = [];
        $presentKlas = [];
        foreach ($rows as $row) {
            $pb7 = trim((string) $row->pb7) !== '' ? (string) $row->pb7 : $blank;
            $pb712 = trim((string) $row->pb712) !== '' ? (string) $row->pb712 : $blank;
            $klas = trim((string) $row->klasifikasi) !== '' ? (string) $row->klasifikasi : $blankKlas;
            $total = (float) $row->total;

            $agg[$pb7][$pb712][$klas] = ($agg[$pb7][$pb712][$klas] ?? 0) + $total;
            $presentKlas[$klas] = true;
        }

        // Urutkan kolom klasifikasi: kanonik dulu, sisanya menyusul.
        $categories = array_values(array_filter(self::KLASIFIKASI_ORDER, fn ($k) => isset($presentKlas[$k])));
        foreach (array_keys($presentKlas) as $k) {
            if (! in_array($k, $categories, true)) {
                $categories[] = $k;
            }
        }

        ksort($agg);
        $groups = [];
        $grand = array_fill_keys($categories, 0.0);
        $grandTotal = 0.0;

        foreach ($agg as $pb7 => $pb712Map) {
            ksort($pb712Map);
            $groupRows = [];
            $subtotal = array_fill_keys($categories, 0.0);
            $subtotalTotal = 0.0;

            foreach ($pb712Map as $pb712 => $klasMap) {
                $values = array_fill_keys($categories, 0.0);
                $rowTotal = 0.0;
                foreach ($klasMap as $klas => $total) {
                    $values[$klas] = ($values[$klas] ?? 0) + $total;
                    $rowTotal += $total;
                    $subtotal[$klas] = ($subtotal[$klas] ?? 0) + $total;
                    $grand[$klas] = ($grand[$klas] ?? 0) + $total;
                }
                $subtotalTotal += $rowTotal;
                $grandTotal += $rowTotal;
                $groupRows[] = ['pb712' => $pb712, 'values' => $values, 'total' => $rowTotal];
            }

            $groups[] = [
                'pb7' => $pb7,
                'rows' => $groupRows,
                'subtotal' => $subtotal,
                'subtotal_total' => $subtotalTotal,
            ];
        }

        return [
            'categories' => $categories,
            'groups' => $groups,
            'grand' => $grand,
            'grand_total' => $grandTotal,
            'row_count' => count($rows),
        ];
    }
}
