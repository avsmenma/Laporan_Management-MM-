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

        return response()->json([
            'success' => true,
            'meta' => $this->buildMeta($batch, $unit, $type, $komoditi),
            'context' => [
                'type' => $type,
                'kode_baris' => (string) $request->kode,
                'column_key' => (string) $request->column,
                'template' => $template ? [
                    'urutan' => $template->urutan,
                    'kode' => $template->kode,
                    'uraian' => $template->uraian,
                    'source' => $template->source,
                ] : null,
                'message' => 'Rincian sumber sel akan diperluas pada prompt_09.',
            ],
            'sources' => $this->drilldownSources($type, $batch, $unit, $komoditi, $template),
        ]);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function drilldownSources(string $type, Batch $batch, RefUnit $unit, ?string $komoditi, ?LmTemplateRow $template): array
    {
        if (! $template) {
            return [];
        }

        if ($type === 'LM16') {
            return DB::table('pks_biaya')
                ->where('batch_id', $batch->id)
                ->where('plant_code', $unit->code)
                ->where('period', $batch->month)
                ->limit(25)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        if ($template->source === 'BTL') {
            return DB::table('db_ohc')
                ->where('batch_id', $batch->id)
                ->where('komoditi', $komoditi)
                ->where('plant_code', $unit->code)
                ->where('period', $batch->month)
                ->limit(25)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return DB::table('db_wbs_raw')
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $batch->month)
            ->limit(25)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
