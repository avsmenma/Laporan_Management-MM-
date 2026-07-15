<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman /laba-rugi/penjualan. Sumber: penjualan_produk (sheet "Data" ekspor GL SAP).
 * Mereplikasi 3 sheet template workbook "Penjualan Produk Tahun 2026":
 *  - buyer (temp Buyer)       : grup produk → customer; blok BULAN INI + SD BULAN INI.
 *  - plant (temp Plant)       : grup produk → profit center; blok BULAN INI + SD BULAN INI.
 *  - all   (temp Buyer Plant) : grup produk → customer; kolom per plant + JUMLAH; BULAN INI saja.
 * Nilai & qty NEGATIF (kredit pendapatan) — ditampilkan apa adanya (kurung merah di UI).
 */
class PenjualanProdukController extends Controller
{
    use AuthorizesReportRequests;

    /** Urutan grup produk sesuai template; material lain menyusul di akhir. */
    private const MATERIAL_ORDER = ['CPO', 'INTI SAWIT', 'Lump', 'TBS (TANDAN BUAH SEGAR)'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $periods = DB::table('penjualan_produk')
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('period')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null,
                'plants' => [], 'buyer' => null, 'plant' => null, 'all' => null,
            ]);
        }

        $year = $periods[0]['year'];
        $month = $periods[0]['month'];
        $reqYear = $request->integer('year');
        $reqMonth = $request->integer('month');
        if ($reqYear && $reqMonth) {
            foreach ($periods as $p) {
                if ($p['year'] === $reqYear && $p['month'] === $reqMonth) {
                    $year = $reqYear;
                    $month = $reqMonth;
                    break;
                }
            }
        }

        $plants = $this->plantColumns($year, $month);

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'plants' => $plants,
            'buyer' => $this->tabDua($year, $month, 'customer'),
            'plant' => $this->tabDua($year, $month, 'plant'),
            'all' => $this->tabAll($year, $month, $plants),
        ]);
    }

    /** Rp/Kg pakai pola aman: penyebut 0 → 0. Qty & nilai negatif → hasil positif. */
    private static function safeDiv(float $a, float $b): float
    {
        return $b != 0.0 ? $a / $b : 0.0;
    }

    /** Blok nilai {qty, rpkg, nilai}. */
    private static function blok(float $qty, float $nilai): array
    {
        return ['qty' => $qty, 'rpkg' => self::safeDiv($nilai, $qty), 'nilai' => $nilai];
    }

    /** Urut material sesuai template; yang tak dikenal di akhir (alfabetis). */
    private static function sortMaterials(array $mats): array
    {
        usort($mats, function ($a, $b) {
            $ia = array_search($a, self::MATERIAL_ORDER, true);
            $ib = array_search($b, self::MATERIAL_ORDER, true);
            $ia = $ia === false ? 99 : $ia;
            $ib = $ib === false ? 99 : $ib;

            return ($ia <=> $ib) ?: strcmp($a, $b);
        });

        return $mats;
    }

    /**
     * Kolom plant tab ALL: profit center yang muncul pada cakupan s.d bulan terpilih,
     * urut pabrik (5F) dulu lalu kebun (5E), kode menaik — sama dengan urutan template.
     *
     * @return array<int, array{code: string, nama: string}>
     */
    private function plantColumns(int $year, int $month): array
    {
        $rows = DB::table('penjualan_produk')
            ->where('year', $year)->where('period', '<=', $month)
            ->select('profit_center', DB::raw('MAX(profit_center_desc) AS nama'))
            ->groupBy('profit_center')->get();

        $list = $rows->map(fn ($r) => ['code' => (string) $r->profit_center, 'nama' => (string) $r->nama])->all();
        usort($list, function ($a, $b) {
            $ga = str_starts_with($a['code'], '5F') ? 0 : 1;
            $gb = str_starts_with($b['code'], '5F') ? 0 : 1;

            return ($ga <=> $gb) ?: strcmp($a['code'], $b['code']);
        });

        return $list;
    }

    /**
     * Agregat per material×kunci untuk satu cakupan periode ('=' bulan ini, '<=' s.d).
     * $dim 'customer' → kunci customer_code; 'plant' → kunci profit_center.
     *
     * @return array<string, array<string, array{name: string, qty: float, nilai: float}>>
     */
    private function agg(int $year, int $month, string $op, string $dim): array
    {
        [$key, $name] = $dim === 'plant'
            ? ['profit_center', 'profit_center_desc']
            : ['customer_code', 'customer_name'];

        $rows = DB::table('penjualan_produk')
            ->where('year', $year)->where('period', $op, $month)
            ->selectRaw("material_desc, {$key} AS k, MAX({$name}) AS nm, SUM(qty) AS q, SUM(amount) AS v")
            ->groupBy('material_desc', $key)->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->material_desc][(string) ($r->k ?? '')] = [
                'name' => (string) ($r->nm ?? ''),
                'qty' => (float) $r->q,
                'nilai' => (float) $r->v,
            ];
        }

        return $map;
    }

    /**
     * Tab BUYER / PLANT (struktur sama, dimensi baris beda): grup produk → baris →
     * Jumlah per grup → Total; tiap baris blok bi & sd {qty, rpkg, nilai}.
     */
    private function tabDua(int $year, int $month, string $dim): array
    {
        $bi = $this->agg($year, $month, '=', $dim);
        $sd = $this->agg($year, $month, '<=', $dim);

        $groups = [];
        $totBi = [0.0, 0.0];
        $totSd = [0.0, 0.0];
        foreach (self::sortMaterials(array_keys($sd + $bi)) as $mat) {
            $sdRows = $sd[$mat] ?? [];
            $biRows = $bi[$mat] ?? [];
            $codes = array_keys($sdRows + $biRows);
            sort($codes);

            $rows = [];
            $jmlBi = [0.0, 0.0];
            $jmlSd = [0.0, 0.0];
            foreach ($codes as $code) {
                $b = $biRows[$code] ?? null;
                $s = $sdRows[$code] ?? null;
                $rows[] = [
                    'code' => (string) $code,
                    'name' => (string) ($s['name'] ?? $b['name'] ?? ''),
                    'bi' => self::blok($b['qty'] ?? 0.0, $b['nilai'] ?? 0.0),
                    'sd' => self::blok($s['qty'] ?? 0.0, $s['nilai'] ?? 0.0),
                ];
                $jmlBi[0] += $b['qty'] ?? 0.0;
                $jmlBi[1] += $b['nilai'] ?? 0.0;
                $jmlSd[0] += $s['qty'] ?? 0.0;
                $jmlSd[1] += $s['nilai'] ?? 0.0;
            }
            $totBi[0] += $jmlBi[0];
            $totBi[1] += $jmlBi[1];
            $totSd[0] += $jmlSd[0];
            $totSd[1] += $jmlSd[1];
            $groups[] = [
                'material' => $mat,
                'rows' => $rows,
                'jumlah' => ['bi' => self::blok(...$jmlBi), 'sd' => self::blok(...$jmlSd)],
            ];
        }

        return [
            'groups' => $groups,
            'total' => ['bi' => self::blok(...$totBi), 'sd' => self::blok(...$totSd)],
        ];
    }

    /**
     * Tab ALL (temp Buyer Plant): grup produk → customer; nilai per plant + JUMLAH —
     * BULAN INI saja (sesuai template).
     *
     * @param  array<int, array{code: string, nama: string}>  $plants
     */
    private function tabAll(int $year, int $month, array $plants): array
    {
        $rows = DB::table('penjualan_produk')
            ->where('year', $year)->where('period', $month)
            ->selectRaw('material_desc, customer_code, MAX(customer_name) AS nm, profit_center, SUM(qty) AS q, SUM(amount) AS v')
            ->groupBy('material_desc', 'customer_code', 'profit_center')->get();

        // map[material][customer] = {name, plants: pc → [qty, nilai]}
        $map = [];
        foreach ($rows as $r) {
            $mat = (string) $r->material_desc;
            $code = (string) ($r->customer_code ?? '');
            $map[$mat][$code] ??= ['name' => (string) ($r->nm ?? ''), 'plants' => []];
            $pc = (string) $r->profit_center;
            $cur = $map[$mat][$code]['plants'][$pc] ?? [0.0, 0.0];
            $map[$mat][$code]['plants'][$pc] = [$cur[0] + (float) $r->q, $cur[1] + (float) $r->v];
        }

        $mkRow = function (array $plantVals) use ($plants): array {
            $out = ['plants' => [], 'jumlah' => null];
            $jq = 0.0;
            $jv = 0.0;
            foreach ($plants as $p) {
                [$q, $v] = $plantVals[$p['code']] ?? [0.0, 0.0];
                $out['plants'][$p['code']] = self::blok($q, $v);
                $jq += $q;
                $jv += $v;
            }
            $out['jumlah'] = self::blok($jq, $jv);

            return $out;
        };

        $groups = [];
        $totPlants = [];
        foreach (self::sortMaterials(array_keys($map)) as $mat) {
            $codes = array_keys($map[$mat]);
            sort($codes);

            $rowsOut = [];
            $jmlPlants = [];
            foreach ($codes as $code) {
                $c = $map[$mat][$code];
                $rowsOut[] = [
                    'code' => (string) $code,
                    'name' => $c['name'],
                    ...$mkRow($c['plants']),
                ];
                foreach ($c['plants'] as $pc => [$q, $v]) {
                    $cur = $jmlPlants[$pc] ?? [0.0, 0.0];
                    $jmlPlants[$pc] = [$cur[0] + $q, $cur[1] + $v];
                    $curT = $totPlants[$pc] ?? [0.0, 0.0];
                    $totPlants[$pc] = [$curT[0] + $q, $curT[1] + $v];
                }
            }
            $groups[] = [
                'material' => $mat,
                'rows' => $rowsOut,
                'jumlah' => $mkRow($jmlPlants),
            ];
        }

        return ['groups' => $groups, 'total' => $mkRow($totPlants)];
    }
}
