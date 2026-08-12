<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tab PENYESUAIAN halaman /laba-rugi/persediaan — input manual lima kolom
 * kuantitas yang belum punya sumber sistem (Transfer Penerimaan, Transfer
 * Pengeluaran, Susut, STO GR, STO GI), meniru "FORM PENYESUAIAN.xlsx".
 * Pola CRUD sama dengan tab PROPORSI Beban Administrasi.
 */
class PersediaanPenyesuaianController extends Controller
{
    /** Nilai khusus dropdown form untuk baris "Penyesuaian atas nilai persediaan akhir". */
    public const KHUSUS = 'Penyesuaian Nilai Akhir';

    /**
     * Pilihan PLANT pada form (Data!F2:F18). Kode 5R00 dipakai DUA unit kerja
     * (IPP Tayan & Tanah Merah) sehingga dipecah jadi 5R00-1/5R00-2 — tanpa itu
     * isian selalu jatuh ke baris pertama saja (permintaan user).
     */
    public const PLANTS = [
        '5R00-1', '5R00-2', '5F01', '5F04', '5F07', '5F08', '5F09', '5F14', '5F15', '5F21', '5F22',
        '5F20', '5E06', '5E11', '5E13', '5E19', '5E12', self::KHUSUS,
    ];

    /** Label dropdown untuk kode yang perlu penjelas unit (selain ini pakai kodenya). */
    public const PLANT_LABELS = [
        '5R00-1' => '5R00-1 (IPP Tayan)',
        '5R00-2' => '5R00-2 (Tanah Merah)',
    ];

    /** Pilihan MATERIAL pada form (Data!G2:G6). */
    public const PRODUCTS = [
        '- Minyak Sawit', '- Inti Sawit', '- Tandan Buah Segar', 'LUMP', self::KHUSUS,
    ];

    /**
     * Kolom nilai yang boleh diisi. Lima pertama satuan Kg; `nilai_akhir`
     * satuan Rupiah — mengisi kolom NILAI PERSEDIAAN AKHIR pada baris
     * "Penyesuaian atas nilai persediaan akhir".
     *
     * @return array<int, string>
     */
    public static function kolomNilai(): array
    {
        return ['transfer_masuk', 'transfer_keluar', 'susut', 'sto_gr', 'sto_gi', 'nilai_akhir'];
    }

    /** Daftar seluruh baris penyesuaian (semua periode). */
    public function index(): JsonResponse
    {
        $rows = DB::table('persediaan_penyesuaian')
            ->orderBy('year')->orderBy('month')->orderBy('plant_code')->orderBy('product')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'year' => (int) $r->year,
                'month' => (int) $r->month,
                'plant_code' => $r->plant_code,
                'product' => $r->product,
                'transfer_masuk' => (float) $r->transfer_masuk,
                'transfer_keluar' => (float) $r->transfer_keluar,
                'susut' => (float) $r->susut,
                'sto_gr' => (float) $r->sto_gr,
                'sto_gi' => (float) $r->sto_gi,
                'nilai_akhir' => (float) $r->nilai_akhir,
            ]);

        return response()->json([
            'rows' => $rows,
            'plants' => self::PLANTS,
            'plantLabels' => self::PLANT_LABELS,
            'products' => self::PRODUCTS,
        ]);
    }

    /**
     * Simpan satu baris (Operator/Admin). Tanpa id → baris baru. Kombinasi
     * (tahun, bulan, plant, produk) BOLEH berulang — satu plant bisa punya
     * beberapa baris pada periode sama (mis. baris transfer terpisah dari baris
     * susut); nilainya dijumlahkan saat dibaca halaman Persediaan.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'plant_code' => ['required', 'in:'.implode(',', self::PLANTS)],
            'product' => ['required', 'in:'.implode(',', self::PRODUCTS)],
            'transfer_masuk' => ['required', 'numeric'],
            'transfer_keluar' => ['required', 'numeric'],
            'susut' => ['required', 'numeric'],
            'sto_gr' => ['required', 'numeric'],
            'sto_gi' => ['required', 'numeric'],
            // Kolom baru — dibuat opsional agar halaman versi lama (JS ter-cache)
            // tetap bisa menyimpan; tidak dikirim → dianggap 0.
            'nilai_akhir' => ['nullable', 'numeric'],
        ]);

        $values = [
            'year' => $data['year'], 'month' => $data['month'],
            'plant_code' => $data['plant_code'], 'product' => $data['product'],
            'updated_at' => now(),
        ];
        foreach (self::kolomNilai() as $k) {
            $values[$k] = $data[$k] ?? 0;
        }

        $q = DB::table('persediaan_penyesuaian');
        if (isset($data['id'])) {
            $q->where('id', $data['id'])->update($values);
            $id = (int) $data['id'];
        } else {
            $id = (int) $q->insertGetId($values + ['created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    /** Hapus satu baris (Operator/Admin). */
    public function destroy(int $id): JsonResponse
    {
        DB::table('persediaan_penyesuaian')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
