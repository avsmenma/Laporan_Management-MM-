<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\BebanUsahaController;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Drill-down sumber data halaman Laba Rugi (pola sama dengan /kebun & /pabrik):
 *  - Tahap 1 (pivot)  : GET /report-data/laba-rugi/drilldown
 *  - Tahap 2 (mentah) : GET /report-data/laba-rugi/drilldown-deep
 *
 * Parameter umum: page=penjualan|admin|bol|penj|pendapatan, year, month.
 *  - admin/bol/penj/pendapatan : tab, row (indeks baris di BebanUsahaController),
 *                                field=bln|sd|sdbl.
 *  - penjualan      : tab=buyer|plant|all, mat, code, rowType=detail|jumlah|total,
 *                     blok=bl|bi|sd|jml|p_<plant>, measure=qty|nilai.
 * Deep menambah kunci sel pivot: g (grup), r (baris), c (periode, opsional).
 *
 * Dimensi pivot per halaman:
 *  - penjualan : grup=Produk (material_desc), baris=Plant / Customer (dimensi lawan tab).
 *  - admin     : grup=Profit Center, baris=Cost Center.
 *  - bol       : grup=Kodering (class_code), baris=Profit Center.
 *  - penj      : grup=Cost Center (R5OBPJ101 CPO / R5OBPJ102 PK), baris=Akun GL.
 *  - pendapatan: grup=Profit Center, baris=Akun GL (cost center kosong di file ini);
 *                nilai pivot dibalik tanda agar sejalan dengan sel (pendapatan kredit).
 * Kolom kategori pivot = bulan-bulan pada cakupan sel yang diklik.
 */
class LabaRugiDrilldownController extends Controller
{
    use AuthorizesReportRequests;

    /** Batas baris tahap 2 agar respons tidak membengkak (jumlah asli tetap dilaporkan). */
    private const MAX_DEEP_ROWS = 20000;

    /** Kolom rincian mentah beban_usaha_gl (halaman admin & bol). */
    private const GL_COLUMNS = [
        ['field' => 'posting_date', 'label' => 'Tgl Posting', 'numeric' => false],
        ['field' => 'document_number', 'label' => 'No. Dokumen', 'numeric' => false],
        ['field' => 'account', 'label' => 'Akun', 'numeric' => false],
        ['field' => 'gl_account_desc', 'label' => 'Nama Akun GL', 'numeric' => false],
        ['field' => 'profit_center', 'label' => 'Profit Center', 'numeric' => false],
        ['field' => 'profit_center_desc', 'label' => 'Nama Profit Center', 'numeric' => false],
        ['field' => 'cost_center', 'label' => 'Cost Center', 'numeric' => false],
        ['field' => 'cost_element', 'label' => 'Cost Element', 'numeric' => false],
        ['field' => 'text', 'label' => 'Teks', 'numeric' => false],
        ['field' => 'class_code', 'label' => 'Kode', 'numeric' => false],
        ['field' => 'class_desc', 'label' => 'Klasifikasi', 'numeric' => false],
        ['field' => 'amount', 'label' => 'Nilai (Rp)', 'numeric' => true],
    ];

    /** Kolom rincian mentah penjualan_produk. */
    private const PENJUALAN_COLUMNS = [
        ['field' => 'posting_date', 'label' => 'Tgl Posting', 'numeric' => false],
        ['field' => 'document_number', 'label' => 'No. Dokumen', 'numeric' => false],
        ['field' => 'account', 'label' => 'Akun', 'numeric' => false],
        ['field' => 'gl_account_desc', 'label' => 'Nama Akun GL', 'numeric' => false],
        ['field' => 'profit_center', 'label' => 'Profit Center', 'numeric' => false],
        ['field' => 'profit_center_desc', 'label' => 'Nama Plant', 'numeric' => false],
        ['field' => 'material_code', 'label' => 'Kode Material', 'numeric' => false],
        ['field' => 'material_desc', 'label' => 'Material', 'numeric' => false],
        ['field' => 'qty', 'label' => 'Qty', 'numeric' => true],
        ['field' => 'uom', 'label' => 'Satuan', 'numeric' => false],
        ['field' => 'amount', 'label' => 'Nilai (Rp)', 'numeric' => true],
        ['field' => 'customer_code', 'label' => 'Kode Customer', 'numeric' => false],
        ['field' => 'customer_name', 'label' => 'Nama Customer', 'numeric' => false],
        ['field' => 'document_type', 'label' => 'Tipe Dok', 'numeric' => false],
        ['field' => 'reference', 'label' => 'Referensi', 'numeric' => false],
    ];

    /** Tahap 1: pivot rincian sumber untuk sel yang diklik. */
    public function pivot(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);
        [$q, $cfg] = $this->scopedQuery($request);

        return response()->json(['pivot' => $this->buildPivot($q, $cfg)]);
    }

    /** Tahap 2: baris sumber mentah apa adanya untuk sel pivot yang diklik. */
    public function deep(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);
        [$q, $cfg] = $this->scopedQuery($request);

        $this->applyKeyFilter($q, $cfg['g'], $request->query('g'));
        $this->applyKeyFilter($q, $cfg['r'], $request->query('r'));
        if ($request->filled('c')) {
            $q->where('period', $request->integer('c'));
        }

        $hasQty = $cfg['qtyField'] !== '';
        $agg = (clone $q)->selectRaw(
            'COUNT(*) AS c, COALESCE(SUM(amount), 0) AS v'.($hasQty ? ", COALESCE(SUM({$cfg['qtyField']}), 0) AS qv" : '')
        )->first();
        $rowCount = (int) ($agg->c ?? 0);
        $subtotal = (float) ($agg->v ?? 0);
        $qtySubtotal = $hasQty ? (float) ($agg->qv ?? 0) : 0.0;

        $fields = array_column($cfg['columns'], 'field');
        $items = $q->orderBy('posting_date')->orderBy('id')
            ->limit(self::MAX_DEEP_ROWS)
            ->get($fields)
            ->map(fn ($r) => (array) $r)
            ->all();

        $label = $cfg['sectionLabel'];
        if ($rowCount > count($items)) {
            $label .= ' — ditampilkan '.number_format(count($items), 0, ',', '.').' baris pertama';
        }

        return response()->json(['detail' => [
            'sections' => [[
                'label' => $label,
                'value_field' => 'amount',
                'qty_field' => $cfg['qtyField'],
                'columns' => $cfg['columns'],
                'rows' => $items,
                'subtotal' => $subtotal,
                'qty_subtotal' => $qtySubtotal,
                'row_count' => $rowCount,
            ]],
            'row_count' => $rowCount,
        ]]);
    }

    /**
     * Query sumber sesuai sel yang diklik (belum termasuk kunci sel pivot) +
     * konfigurasi dimensi pivot & kolom rincian mentah.
     *
     * @return array{0: Builder, 1: array<string, mixed>}
     */
    private function scopedQuery(Request $request): array
    {
        $page = (string) $request->query('page');
        $year = $request->integer('year');
        $month = $request->integer('month');
        abort_unless(in_array($page, ['penjualan', 'admin', 'bol', 'penj', 'pendapatan'], true), 422, 'Parameter page tidak dikenal.');
        abort_unless($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12, 422, 'Periode tidak valid.');

        return match ($page) {
            'penjualan' => $this->scopePenjualan($request, $year, $month),
            'admin' => $this->scopeAdmin($request, $year, $month),
            'penj' => $this->scopePenj($request, $year, $month),
            'pendapatan' => $this->scopePendapatan($request, $year, $month),
            default => $this->scopeBol($request, $year, $month),
        };
    }

    /** Cakupan periode kolom yang diklik: bln = bulan itu, sd = s.d bulan, sdbl = s.d bulan lalu. */
    private function applyPeriodField(Builder $q, string $field, int $month): void
    {
        match ($field) {
            'bln' => $q->where('period', $month),
            'sd' => $q->where('period', '<=', $month),
            default => $q->where('period', '<=', $month - 1),
        };
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function scopeAdmin(Request $request, int $year, int $month): array
    {
        // Tab ADMI KS/KR = hasil perhitungan (Summary × %Proporsi), bukan agregat GL
        // langsung — rincian sumber mentahnya ada di tab SUMMARY.
        abort_unless((string) $request->query('tab', 'summary') === 'summary', 422, 'Tab ADMI KS/ADMI KR adalah hasil perhitungan Summary × %Proporsi — rincian sumber ada di tab SUMMARY.');
        $field = (string) $request->query('field');
        abort_unless(in_array($field, ['bln', 'sd', 'sdbl'], true), 422, 'Parameter field tidak dikenal.');

        $rows = BebanUsahaController::rowsBebanAdministrasi();
        $i = $request->integer('row', -1);
        abort_unless(isset($rows[$i]), 422, 'Baris tidak dikenal.');

        $depre = 'Beban Depresiasi dan Amortisasi';
        // Label dengan pemetaan langsung (di luar penampung Lain-Lain).
        $known = [];
        foreach ($rows as $r) {
            if (($r['t'] ?? 'detail') === 'detail' && $r['u'] !== 'Lain-Lain') {
                $known[] = $r['u'];
            }
        }

        $q = DB::table('beban_usaha_gl')->where('report_type', 'ADMIN')->where('year', $year);
        $this->applyPeriodField($q, $field, $month);

        $type = $rows[$i]['t'] ?? 'detail';
        $label = $rows[$i]['u'];
        if ($type === 'detail' && $label !== 'Lain-Lain') {
            $q->whereRaw('TRIM(class_desc) = ?', [$label]);
        } elseif ($type === 'detail') {
            // Lain-Lain: klasifikasi bernama itu + seluruh klasifikasi di luar peta.
            $q->where(function ($w) use ($known): void {
                $w->whereNull('class_desc')->orWhereNotIn(DB::raw('TRIM(class_desc)'), $known);
            });
        } elseif ($type === 'subtotal') {
            // Jumlah = seluruh rincian (semua klasifikasi kecuali Depresiasi).
            $q->where(function ($w) use ($depre): void {
                $w->whereNull('class_desc')->orWhereRaw('TRIM(class_desc) <> ?', [$depre]);
            });
        }
        // total: seluruh baris — tanpa filter klasifikasi.

        return [$q, [
            'g' => 'profit_center', 'gDesc' => 'profit_center_desc', 'gLabel' => 'Profit Center',
            'r' => 'cost_center', 'rDesc' => null, 'rLabel' => 'Cost Center',
            'val' => 'amount',
            'sectionLabel' => 'beban_usaha_gl — GL SAP (ADMIN)',
            'columns' => self::GL_COLUMNS,
            'qtyField' => '',
        ]];
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function scopePenj(Request $request, int $year, int $month): array
    {
        abort_unless((string) $request->query('tab', 'all') === 'all', 422, 'Parameter tab tidak dikenal.');
        $field = (string) $request->query('field');
        abort_unless(in_array($field, ['bln', 'sd', 'sdbl'], true), 422, 'Parameter field tidak dikenal.');

        // Struktur baris sama persis dengan BebanUsahaDataController::penjValues().
        [$meta, $sawitByLabel, $subtotals, $iTotal] = BebanUsahaDataController::penjRowLayout();
        $i = $request->integer('row', -1);
        abort_unless(isset($meta[$i]), 422, 'Baris tidak dikenal.');

        $known = array_keys(array_diff_key($sawitByLabel, ['Lain - Lain' => 0]));

        $q = DB::table('beban_usaha_gl')->where('report_type', 'PENJ')->where('year', $year);
        $this->applyPeriodField($q, $field, $month);

        $m = $meta[$i];
        if ($i === $iTotal || $i === ($subtotals['860.1'] ?? -1)) {
            // Jumlah Sawit & Jumlah Seluruh: seluruh baris PENJ (semuanya sawit).
        } elseif ($m['sec'] === '860.0') {
            $q->whereRaw('1 = 0'); // seksi Karet tak punya sumber di file ini
        } elseif ($m['t'] === 'detail' && $m['u'] !== 'Lain - Lain') {
            $q->whereRaw('TRIM(class_desc) = ?', [$m['u']]);
        } else {
            // Lain - Lain: klasifikasi bernama itu + seluruh klasifikasi di luar peta.
            $q->where(function ($w) use ($known): void {
                $w->whereNull('class_desc')->orWhereNotIn(DB::raw('TRIM(class_desc)'), $known);
            });
        }

        return [$q, [
            'g' => 'cost_center', 'gDesc' => null, 'gLabel' => 'Cost Center',
            'r' => 'account', 'rDesc' => 'gl_account_desc', 'rLabel' => 'Akun GL',
            'val' => 'amount',
            'sectionLabel' => 'beban_usaha_gl — GL SAP (PENJ)',
            'columns' => self::GL_COLUMNS,
            'qtyField' => '',
        ]];
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function scopePendapatan(Request $request, int $year, int $month): array
    {
        // Baru tab SUMMARY yang punya data (tab KS/KR belum diisi).
        abort_unless((string) $request->query('tab', 'summary') === 'summary', 422, 'Baru tab SUMMARY yang punya sumber data.');
        $field = (string) $request->query('field');
        abort_unless(in_array($field, ['bln', 'sd', 'sdbl'], true), 422, 'Parameter field tidak dikenal.');

        // Struktur baris sama persis dengan BebanUsahaDataController::pendapatanValues().
        [$idxByLabel, $ksoByLabel, $iJumlah, $iJumlahKso, $iTotal] = BebanUsahaDataController::pendapatanRowLayout();
        $rows = BebanUsahaController::rowsPendapatanSummary();
        $i = $request->integer('row', -1);
        abort_unless(isset($rows[$i]), 422, 'Baris tidak dikenal.');

        $ksoLabels = array_keys($ksoByLabel);
        $known = array_keys(array_diff_key($idxByLabel, ['Lain - Lain' => 0]));

        $q = DB::table('beban_usaha_gl')->where('report_type', 'PENDPT')->where('year', $year);
        $this->applyPeriodField($q, $field, $month);

        $label = $rows[$i]['u'];
        if ($i === $iTotal) {
            // Total: seluruh baris — tanpa filter.
        } elseif ($i === $iJumlah) {
            // Jumlah rincian = semua di luar baris KSO (termasuk tampungan Lain - Lain).
            $q->where(function ($w) use ($ksoLabels): void {
                $w->whereNull('class_desc')->orWhereNotIn(DB::raw('TRIM(class_desc)'), $ksoLabels);
            });
        } elseif ($i === $iJumlahKso) {
            $q->whereIn(DB::raw('TRIM(class_desc)'), $ksoLabels);
        } elseif ($label !== 'Lain - Lain') {
            $q->whereRaw('TRIM(class_desc) = ?', [$label]);
        } else {
            // Lain - Lain: klasifikasi bernama itu + seluruh klasifikasi di luar peta.
            $q->where(function ($w) use ($known): void {
                $w->whereNull('class_desc')->orWhereNotIn(DB::raw('TRIM(class_desc)'), $known);
            });
        }

        return [$q, [
            'g' => 'profit_center', 'gDesc' => 'profit_center_desc', 'gLabel' => 'Profit Center',
            'r' => 'account', 'rDesc' => 'gl_account_desc', 'rLabel' => 'Akun GL',
            // Pendapatan tersimpan minus (kredit) → pivot dibalik tanda agar sama
            // dengan sel halaman; tahap 2 (mentah) tetap apa adanya.
            'val' => '-amount',
            'sectionLabel' => 'beban_usaha_gl — GL SAP (PENDPT)',
            'columns' => self::GL_COLUMNS,
            'qtyField' => '',
        ]];
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function scopeBol(Request $request, int $year, int $month): array
    {
        $tab = (string) $request->query('tab', 'summary');
        abort_unless(in_array($tab, ['summary', 'ks', 'kr'], true), 422, 'Parameter tab tidak dikenal.');
        $field = (string) $request->query('field');
        abort_unless(in_array($field, ['bln', 'sd', 'sdbl'], true), 422, 'Parameter field tidak dikenal.');

        $rowsDef = BebanUsahaController::rowsBolSummary();
        $i = $request->integer('row', -1);
        abort_unless(isset($rowsDef[$i]), 422, 'Baris tidak dikenal.');

        // Posisi struktural — samakan dengan BebanUsahaDataController::bolValues().
        $iJumlah = null;
        $iJumlahKso = null;
        $iTotal = null;
        foreach ($rowsDef as $idx => $r) {
            $t = $r['t'] ?? 'detail';
            if ($t === 'subtotal') {
                $iJumlah === null ? $iJumlah = $idx : $iJumlahKso = $idx;
            } elseif ($t === 'total') {
                $iTotal = $idx;
            }
        }
        $ksoBase = $iJumlah + 1;

        $detailByCode = BebanUsahaDataController::BOL_DETAIL_BY_CODE;
        $ksoA119 = BebanUsahaDataController::BOL_KSO_A119_BY_PC;
        $iLain = BebanUsahaDataController::BOL_DETAIL_LAIN;
        $kA124 = BebanUsahaDataController::BOL_KSO_A124;
        $knownCodes = array_merge(array_keys($detailByCode), ['A119', 'A124']);
        $ksoPcs = array_keys($ksoA119);

        $q = DB::table('beban_usaha_gl')->where('report_type', 'BOL')->where('year', $year);
        $this->applyPeriodField($q, $field, $month);

        // Kondisi "baris KSO": A124, atau A119 dengan profit center terpetakan.
        $ksoCond = function ($w) use ($ksoPcs): void {
            $w->whereRaw("TRIM(class_code) = 'A124'")
                ->orWhere(function ($w2) use ($ksoPcs): void {
                    $w2->whereRaw("TRIM(class_code) = 'A119'")
                        ->where(function ($w3) use ($ksoPcs): void {
                            foreach ($ksoPcs as $pc) {
                                $w3->orWhere('profit_center', 'like', $pc.'%');
                            }
                        });
                });
        };

        if ($i === $iTotal) {
            // Total: seluruh baris — tanpa filter.
        } elseif ($i === $iJumlah) {
            $q->where(function ($w) use ($ksoCond): void {
                $w->whereNull('class_code')->orWhereNot($ksoCond);
            });
        } elseif ($i === $iJumlahKso) {
            $q->where($ksoCond);
        } elseif ($i >= $ksoBase && $i < $iJumlahKso) {
            $k = $i - $ksoBase;
            $pc = array_search($k, $ksoA119, true);
            if ($k === $kA124) {
                $q->whereRaw("TRIM(class_code) = 'A124'");
            } elseif ($pc !== false) {
                $q->whereRaw("TRIM(class_code) = 'A119'")->where('profit_center', 'like', $pc.'%');
            } else {
                $q->whereRaw('1 = 0'); // baris KSO tanpa pemetaan sumber
            }
        } elseif ($i === $iLain) {
            // Lain-Lain: A168 + kodering di luar peta + A119 profit center tak terpetakan.
            $q->where(function ($w) use ($knownCodes, $ksoPcs): void {
                $w->whereNull('class_code')
                    ->orWhereRaw("TRIM(class_code) = 'A168'")
                    ->orWhereNotIn(DB::raw('TRIM(class_code)'), $knownCodes)
                    ->orWhere(function ($w2) use ($ksoPcs): void {
                        $w2->whereRaw("TRIM(class_code) = 'A119'");
                        foreach ($ksoPcs as $pc) {
                            $w2->where('profit_center', 'not like', $pc.'%');
                        }
                    });
            });
        } else {
            $codes = array_keys($detailByCode, $i, true);
            $codes === []
                ? $q->whereRaw('1 = 0') // baris rincian tanpa pemetaan kodering
                : $q->whereIn(DB::raw('TRIM(class_code)'), $codes);
        }

        // Tab KARET = A119@5E12 (KSO Kumai) + A123@5F20 (PKR); KELAPA SAWIT = sisanya.
        $karet = function ($w): void {
            $w->where(function ($w2): void {
                $w2->whereRaw("TRIM(class_code) = 'A119'")->where('profit_center', 'like', '5E12%');
            })->orWhere(function ($w2): void {
                $w2->whereRaw("TRIM(class_code) = 'A123'")->where('profit_center', 'like', '5F20%');
            });
        };
        if ($tab === 'kr') {
            $q->where($karet);
        } elseif ($tab === 'ks') {
            $q->where(function ($w) use ($karet): void {
                $w->whereNull('class_code')->orWhereNot($karet);
            });
        }

        return [$q, [
            'g' => 'class_code', 'gDesc' => 'class_desc', 'gLabel' => 'Kodering',
            'r' => 'profit_center', 'rDesc' => 'profit_center_desc', 'rLabel' => 'Profit Center',
            'val' => 'amount',
            'sectionLabel' => 'beban_usaha_gl — GL SAP (BOL)',
            'columns' => self::GL_COLUMNS,
            'qtyField' => '',
        ]];
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function scopePenjualan(Request $request, int $year, int $month): array
    {
        $tab = (string) $request->query('tab', 'buyer');
        abort_unless(in_array($tab, ['buyer', 'plant', 'all'], true), 422, 'Parameter tab tidak dikenal.');
        $rowType = (string) $request->query('rowType', 'detail');
        abort_unless(in_array($rowType, ['detail', 'jumlah', 'total'], true), 422, 'Parameter rowType tidak dikenal.');
        $measure = (string) $request->query('measure', 'nilai');
        abort_unless(in_array($measure, ['qty', 'nilai'], true), 422, 'Parameter measure tidak dikenal.');
        $blok = (string) $request->query('blok', 'bi');

        $q = DB::table('penjualan_produk')->where('year', $year);

        if (str_starts_with($blok, 'p_')) {
            // Tab ALL — kolom satu plant, bulan ini.
            $q->where('period', $month)->where('profit_center', substr($blok, 2));
        } elseif ($blok === 'sd') {
            $q->where('period', '<=', $month);
        } elseif ($blok === 'bl') {
            $q->where('period', $month - 1);
        } elseif (in_array($blok, ['bi', 'jml'], true)) {
            $q->where('period', $month);
        } else {
            abort(422, 'Parameter blok tidak dikenal.');
        }

        if ($rowType !== 'total') {
            $q->where('material_desc', (string) $request->query('mat', ''));
        }
        if ($rowType === 'detail') {
            $code = (string) $request->query('code', '');
            $dimCol = $tab === 'plant' ? 'profit_center' : 'customer_code';
            $code === ''
                ? $q->where(fn ($w) => $w->whereNull($dimCol)->orWhere($dimCol, ''))
                : $q->where($dimCol, $code);
        }

        // Dimensi baris pivot = dimensi LAWAN tab (tab BUYER → per plant, dst).
        $rowIsCustomer = $tab === 'plant';

        return [$q, [
            'g' => 'material_desc', 'gDesc' => null, 'gLabel' => 'Produk',
            'r' => $rowIsCustomer ? 'customer_code' : 'profit_center',
            'rDesc' => $rowIsCustomer ? 'customer_name' : 'profit_center_desc',
            'rLabel' => $rowIsCustomer ? 'Customer' : 'Plant',
            'val' => $measure === 'qty' ? 'qty' : 'amount',
            'sectionLabel' => 'penjualan_produk — GL SAP (Penjualan Produk)',
            'columns' => self::PENJUALAN_COLUMNS,
            'qtyField' => 'qty',
        ]];
    }

    /**
     * Pivot dua level (grup → baris) × kategori bulan; kunci g/r/c dipakai deep.
     *
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function buildPivot(Builder $q, array $cfg): array
    {
        $gd = $cfg['gDesc'] !== null ? "MAX({$cfg['gDesc']}) AS gd" : "'' AS gd";
        $rd = $cfg['rDesc'] !== null ? "MAX({$cfg['rDesc']}) AS rd" : "'' AS rd";
        $agg = $q->selectRaw("{$cfg['g']} AS gk, {$gd}, {$cfg['r']} AS rk, {$rd}, period AS p, SUM({$cfg['val']}) AS v, COUNT(*) AS c")
            ->groupBy($cfg['g'], $cfg['r'], 'period')
            ->get();

        $cats = [];
        $groups = [];
        $grand = 0.0;
        $rowCount = 0;
        foreach ($agg as $row) {
            $gKey = trim((string) ($row->gk ?? ''));
            $rKey = trim((string) ($row->rk ?? ''));
            $p = (int) $row->p;
            $v = (float) $row->v;

            $g = &$groups[$gKey];
            $g ??= ['g' => $gKey, 'label' => $this->pivotLabel($gKey, (string) ($row->gd ?? '')), 'rows' => [], 'subtotal' => [], 'subtotal_total' => 0.0];
            $r = &$g['rows'][$rKey];
            $r ??= ['r' => $rKey, 'label' => $this->pivotLabel($rKey, (string) ($row->rd ?? '')), 'values' => [], 'total' => 0.0];
            $r['values'][$p] = ($r['values'][$p] ?? 0.0) + $v;
            $r['total'] += $v;
            $g['subtotal'][$p] = ($g['subtotal'][$p] ?? 0.0) + $v;
            $g['subtotal_total'] += $v;
            unset($g, $r);

            $cats[$p] = true;
            $grand += $v;
            $rowCount += (int) $row->c;
        }

        ksort($cats);
        ksort($groups);
        $catKeys = array_keys($cats);
        $names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        $outGroups = [];
        foreach ($groups as $g) {
            ksort($g['rows']);
            $g['rows'] = array_values($g['rows']);
            $outGroups[] = $g;
        }

        return [
            'col1' => $cfg['gLabel'],
            'col2' => $cfg['rLabel'],
            'categories' => array_map(fn (int $p): string => $names[$p] ?? (string) $p, $catKeys),
            'cat_keys' => $catKeys,
            'groups' => $outGroups,
            'row_count' => $rowCount,
            'grand_total' => $grand,
        ];
    }

    /** Label baris/grup pivot: kode + deskripsi; kunci kosong = (Tanpa Keterangan). */
    private function pivotLabel(string $key, string $desc): string
    {
        if ($key === '') {
            return '(Tanpa Keterangan)';
        }
        $desc = trim($desc);

        return $desc !== '' && $desc !== $key ? "{$key} — {$desc}" : $key;
    }

    /** Filter kolom kunci pivot pada deep; '' = baris (Tanpa Keterangan) → NULL/kosong. */
    private function applyKeyFilter(Builder $q, string $col, ?string $key): void
    {
        if ($key === null) {
            return;
        }
        $key = trim($key);
        $key === ''
            ? $q->where(fn ($w) => $w->whereNull($col)->orWhereRaw("TRIM({$col}) = ''"))
            : $q->whereRaw("TRIM({$col}) = ?", [$key]);
    }
}
