<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
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
        $unit = $this->findUnit($request->unit);
        $komoditi = strtoupper($request->komoditi);

        // Otorisasi: Viewer hanya boleh lihat batch final/locked
        $this->checkBatchAccess($batch);

        // Ambil data report
        $rows = DB::table('report_lm14')
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
        $unit = $this->findUnit($request->unit);
        $komoditi = strtoupper($request->komoditi);

        $this->checkBatchAccess($batch);

        $rows = DB::table('report_lm13')
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

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unit, 'LM13', $komoditi),
            'columns' => $this->getLm13Columns(),
            'rows' => $rows->map(fn ($row) => $this->formatLm13Row($row)),
        ]);
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
        $unit = $this->findUnit((string) $request->unit);
        $komoditi = $request->filled('komoditi') ? strtoupper((string) $request->komoditi) : null;

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
        $pivot = $this->drilldownPivot($type, $batch, $unit, $komoditi, $template, $columnKey);

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unit, $type, $komoditi),
            'context' => [
                'type' => $type,
                'kode_baris' => (string) $request->kode,
                'column_key' => $columnKey,
                'column_label' => $this->columnLabel($type, $columnKey),
                'template' => $template ? [
                    'urutan' => $template->urutan,
                    'kode' => $template->kode,
                    'uraian' => $template->uraian,
                    'row_type' => $template->row_type,
                    'source' => $template->source,
                ] : null,
                'message' => $pivot === null
                    ? 'Kolom ini tidak memiliki rincian sumber mentah (mis. anggaran, capaian, atau tahun lalu).'
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
        $unit = $this->findUnit((string) $request->unit);
        $komoditi = $request->filled('komoditi') ? strtoupper((string) $request->komoditi) : null;

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
            'detail' => $this->buildDeepList($rows),
        ]);
    }

    /**
     * Gabungkan baris rincian dalam (jumlahkan duplikat antar baris detail), urutkan
     * nilai terbesar dulu, sertakan grand total.
     *
     * @param  array<int, object>  $rows
     * @return array<string, mixed>
     */
    private function buildDeepList(array $rows): array
    {
        $agg = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row->cost_element,
                (string) $row->aktifitas,
                (string) $row->job_name,
                (string) $row->material,
                (string) $row->uom,
            ]);

            if (! isset($agg[$key])) {
                $agg[$key] = [
                    'cost_element' => (string) $row->cost_element,
                    'cost_element_desc' => (string) $row->cost_element_desc,
                    'aktifitas' => (string) $row->aktifitas,
                    'job_name' => (string) $row->job_name,
                    'material' => (string) $row->material,
                    'mat_desc' => (string) $row->mat_desc,
                    'uom' => (string) $row->uom,
                    'qty' => 0.0,
                    'total' => 0.0,
                ];
            }

            $agg[$key]['qty'] += (float) $row->qty;
            $agg[$key]['total'] += (float) $row->total;
        }

        $items = array_values($agg);
        usort($items, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'items' => $items,
            'grand_total' => array_sum(array_column($items, 'total')),
            'row_count' => count($items),
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
    private function checkBatchAccess(Batch $batch): void
    {
        $user = auth()->user();

        // Jika role Viewer, hanya boleh akses batch final/locked
        if ($user && $user->hasRole(Role::VIEWER)) {
            if (! in_array($batch->status, ['final', 'locked'], true)) {
                abort(403, 'Viewer hanya dapat melihat laporan dengan status final atau locked.');
            }
        }
    }

    private function authenticateReportRequest(Request $request): void
    {
        if ($request->user()) {
            return;
        }

        $userId = (int) $request->header('X-LM-Report-User', 0);
        $token = (string) $request->header('X-LM-Report-Token', '');
        $user = $userId > 0 ? User::query()->find($userId) : null;

        if (! $user || ! hash_equals($this->reportToken($user), $token)) {
            abort(401, 'Sesi laporan tidak valid.');
        }

        Auth::onceUsingId($user->id);
    }

    private function reportToken(User $user): string
    {
        return hash_hmac('sha256', "{$user->id}|{$user->email}|{$user->role_id}", config('app.key'));
    }

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
    private function drilldownPivot(string $type, Batch $batch, RefUnit $unit, ?string $komoditi, ?LmTemplateRow $template, string $columnKey): ?array
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
    private function rawSourceQuery(LmTemplateRow $detail, Batch $batch, RefUnit $unit, ?string $komoditi, string $scope): ?array
    {
        $source = $detail->source;
        $kode = (string) $detail->kode;
        if ($source === null || $kode === '') {
            return null;
        }

        $isStafGaji = $source === 'BTL' && $kode === '99-01';

        // Tentukan tabel, kolom nilai, dan kriteria kode (selaras Lm14Service).
        if ($source === 'WBS' || ($isStafGaji && $scope === 'lalu')) {
            $table = 'db_wbs_raw';
            $valueColumn = 'value';
            $codeColumn = 'aktifitas';
            $codeValue = $isStafGaji ? '99-01' : $kode;
        } elseif ($source === 'BTL') {
            $table = 'db_ohc';
            $valueColumn = 'value_obj_crcy';
            if ($isStafGaji) {
                $codeColumn = 'lock';
                $codeValue = 'SP01';
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
            ->where($table.'.komoditi', $komoditi)
            ->where($table.'.plant_code', $unit->code);

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
    private function rawBreakdownRows(LmTemplateRow $detail, Batch $batch, RefUnit $unit, ?string $komoditi, string $scope): \Illuminate\Support\Collection
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
     * baris mentah dikelompokkan per Cost Element / Aktifitas / Job Name / Material,
     * lengkap dgn Qty, UoM, dan nilai — selaras format sheet "RINCIAN" pada contoh.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rawDeepRows(LmTemplateRow $detail, Batch $batch, RefUnit $unit, ?string $komoditi, string $scope, ?string $pb7, ?string $pb712, ?string $klasifikasi): \Illuminate\Support\Collection
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

        // Pemetaan kolom rincian sesuai tabel sumber (db_ohc tak punya aktifitas/job_name asli).
        if ($table === 'db_ohc') {
            [$ce, $ceDesc, $akt, $job, $mat, $matDesc, $qty, $uom] = [
                'db_ohc.cost_element', 'db_ohc.cost_element_name', 'db_ohc.kode', 'db_ohc.co_object_name',
                'db_ohc.material', 'db_ohc.material_description', 'db_ohc.total_quantity', 'db_ohc.posted_uom',
            ];
        } else { // db_wbs_raw
            [$ce, $ceDesc, $akt, $job, $mat, $matDesc, $qty, $uom] = [
                'db_wbs_raw.cost_element', 'db_wbs_raw.cost_element_desc', 'db_wbs_raw.aktifitas', 'db_wbs_raw.job_name',
                'db_wbs_raw.material', 'db_wbs_raw.mat_desc', 'db_wbs_raw.qty', 'db_wbs_raw.uom',
            ];
        }

        return $query
            ->groupBy($ce, $ceDesc, $akt, $job, $mat, $matDesc, $uom)
            ->select(
                DB::raw($ce.' as cost_element'),
                DB::raw($ceDesc.' as cost_element_desc'),
                DB::raw($akt.' as aktifitas'),
                DB::raw($job.' as job_name'),
                DB::raw($mat.' as material'),
                DB::raw($matDesc.' as mat_desc'),
                DB::raw($uom.' as uom'),
                DB::raw('SUM('.$qty.') as qty'),
                DB::raw('SUM('.$table.'.'.$ctx['value'].') as total'),
            )
            ->get();
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
