<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lm27aApiTest extends TestCase
{
    use RefreshDatabase;

    /** Satu baris GL penjualan; nilai kredit (negatif) seperti ekspor SAP. */
    private function row(int $period, string $material, string $pc, float $amount): array
    {
        return [
            'document_number' => 'DOC-1',
            'posting_date' => sprintf('2026-%02d-10', $period),
            'year' => 2026,
            'period' => $period,
            'account' => '41100000',
            'gl_account_desc' => 'Penjualan',
            'profit_center' => $pc,
            'profit_center_desc' => 'Unit',
            'material_code' => 'M1',
            'material_desc' => $material,
            'qty' => -1,
            'uom' => 'KG',
            'amount' => $amount,
            'customer_code' => 'C1',
            'customer_name' => 'PT Pembeli',
            'document_type' => 'RV',
            'reference' => 'REF',
        ];
    }

    private function seedPenjualan(): void
    {
        DB::table('penjualan_produk')->insert([
            // Kelapa Sawit: TBS kebun (5E) + TBS pabrik (5F) + CPO + Inti Sawit.
            $this->row(6, 'TBS (TANDAN BUAH SEGAR)', '5E01000001', -50),
            $this->row(6, 'TBS (TANDAN BUAH SEGAR)', '5F01000001', -70),
            $this->row(5, 'CPO', '5F01000001', -1000),   // bulan lalu → ikut s.d bulan 6
            $this->row(6, 'CPO', '5F01000001', -2000),
            $this->row(6, 'INTI SAWIT', '5F01000001', -300),
            // Karet: Lump kebun lain + Lump Batulicin (baris tersendiri di LM-34).
            $this->row(6, 'Lump', '5E06000001', -25),
            $this->row(6, 'Lump', '5E13000001', -35),
            // Tidak boleh ikut: bulan sesudahnya & material di luar peta LM-34.
            $this->row(7, 'CPO', '5F01000001', -9999),
            $this->row(6, 'Gula', '5F01000001', -777),
        ]);
    }

    private function viewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_penjualan_lokal_per_budidaya_kumulatif(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=6')->assertOk()->json();

        $this->assertSame(2026, $data['year']);
        $this->assertSame(6, $data['month']);
        // Kelapa Sawit = TBS (50+70) + CPO (1000+2000) + Inti Sawit (300) = 3.420;
        // nilai kredit dibalik jadi positif seperti Excel. Karet = Lump 25+35 = 60.
        $this->assertEqualsWithDelta(3420, $data['values']['lokal']['ks'], 0.001);
        $this->assertEqualsWithDelta(60, $data['values']['lokal']['kr'], 0.001);
    }

    public function test_persediaan_awal_sama_dengan_halaman_persediaan(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=7')->assertOk()->json();

        // Angka acuan = baris "Jumlah Persediaan" kolom PERSEDIAAN AWAL TAHUN (Rp)
        // pada /laba-rugi/persediaan (Σ unit + baris penyesuaian), dikirim NEGATIF
        // karena mengurangi laba (tampil dalam kurung).
        $this->assertEqualsWithDelta(-194983848689, $data['values']['persediaan_awal']['ks'], 0.001);
        $this->assertEqualsWithDelta(-241975496, $data['values']['persediaan_awal']['kr'], 0.001);

        // Saldo awal tahun tidak berubah per bulan.
        $lain = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=1')->assertOk()->json();
        $this->assertSame($data['values']['persediaan_awal'], $lain['values']['persediaan_awal']);
    }

    public function test_biaya_produksi_dan_penyusutan_dari_halaman_lm_eksploitasi(): void
    {
        $this->seed();
        $this->seedPenjualan();

        $batch = Batch::query()->create(['code' => 'Batch #2026-06', 'year' => 2026, 'month' => 6, 'status' => 'final']);
        // [unit, komoditi, baris penyusutan, baris sumber biaya produksi] — tiap
        // baris [urutan, uraian, nilai s.d]. Untuk KS nilai ditanam di urutan 62
        // ("Jumlah Beban Produksi Kebun Inti"), BUKAN 68, karena "Jumlah Biaya
        // Produksi" selalu dihitung ulang = 62 + 67 di lapisan presentasi.
        $units = [
            ['5E01', 'KS', [61, 'Jumlah Beban Penyusutan', 30_000_000_000.0], [62, 'Jumlah Beban Produksi Kebun Inti', 900_000_000_000.0]],
            ['5E02', 'KS', [61, 'Jumlah Beban Penyusutan', 11_950_088_015.0], [62, 'Jumlah Beban Produksi Kebun Inti', 364_270_936_505.0]],
            ['5E06', 'KR', [41, 'Jumlah Beban Penyusutan', 1_603_272_138.0], [48, 'Jumlah Biaya Produksi', 25_000_000_000.0]],
        ];

        foreach ($units as [$code, $komoditi, $peny, $prod]) {
            $unit = RefUnit::query()->where('code', $code)->firstOrFail();
            app(Lm13Service::class)->generate($batch, $unit, $komoditi);

            $set = function (array $baris) use ($batch, $unit, $komoditi, $code): void {
                [$urutan, $uraian, $sd] = $baris;
                $tpl = DB::table('lm_template_row')->where('report_type', 'LM13')
                    ->where('komoditi', $komoditi)->where('urutan', $urutan)->first();
                // Kunci urutan baris (a.l. konstanta LM13_BUDIDAYA).
                $this->assertSame($uraian, $tpl->uraian);

                $affected = DB::table('report_lm13')
                    ->where('batch_id', $batch->id)->where('unit_id', $unit->id)
                    ->where('template_id', $tpl->id)->where('blok', 'OLAH_JUAL')
                    ->update(['sd_real_thn_ini' => $sd]);
                $this->assertSame(1, $affected, "Baris urutan {$urutan} OLAH_JUAL {$code} harus ada");
            };
            $set($peny);
            $set($prod);
        }

        $user = $this->viewer();
        $data = $this->actingAs($user)
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=6')->assertOk()->json();

        // Konsolidasi "Semua Unit": Kelapa Sawit = 5E01 + 5E02. Keduanya dikirim
        // NEGATIF (biaya) supaya Harga Pokok Penjualan tinggal menjumlahkan blok.
        $penyusutan = 41_950_088_015.0;
        $biayaProduksi = 1_264_270_936_505.0;
        $this->assertEqualsWithDelta(-$penyusutan, $data['values']['penyusutan']['ks'], 0.001);
        // Biaya Produksi LM-27A = Jumlah Biaya Produksi − Jumlah Beban Penyusutan.
        $this->assertEqualsWithDelta(-($biayaProduksi - $penyusutan), $data['values']['biaya_produksi']['ks'], 0.001);

        // …dan sumbernya harus identik dengan yang tampil di halaman LM Eksploitasi
        // (blok Kebun Sendiri + Pihak III, kolom Real s.d).
        $lm13 = collect($this->actingAs($user)
            ->getJson("/report-data/lm13?batch={$batch->id}&unit=ALL&komoditi=KS")
            ->assertOk()->json('rows'));
        $sd = fn (int $urutan) => (float) $lm13->first(
            fn ($r) => (int) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL'
        )['sd_jumlah'];
        $this->assertEqualsWithDelta(-$sd(61), $data['values']['penyusutan']['ks'], 0.001);
        $this->assertEqualsWithDelta(-($sd(68) - $sd(61)), $data['values']['biaya_produksi']['ks'], 0.001);

        // Hanya kolom Kelapa Sawit yang boleh dihitung Harga Pokok Penjualan-nya.
        $this->assertSame(['ks'], $data['values']['hpp_kolom']);

        // Karet SENGAJA 0 walaupun datanya ada: baris pengolahan KM masih memakai
        // alokasi biaya olah PKS (sawit). Lihat Lm27aController::LM13_BUDIDAYA.
        $this->assertEqualsWithDelta(0, $data['values']['penyusutan']['kr'], 0.001);
        $this->assertEqualsWithDelta(0, $data['values']['biaya_produksi']['kr'], 0.001);
    }

    public function test_persediaan_akhir_sama_dengan_halaman_persediaan(): void
    {
        $this->seedPenjualan();

        // Nilai ZSTOCK: dua baris punya baris di halaman, satu (5E13 Kebun
        // Batulicin) tidak → harus DIABAIKAN, sama seperti tabelnya.
        DB::table('persediaan_nilai')->insert([
            ['year' => 2026, 'period' => 6, 'plant_code' => '5F01', 'unit_name' => 'PKS Gunung Meliau', 'product' => 'Minyak Sawit', 'nilai_rp' => 200_000_000],
            ['year' => 2026, 'period' => 6, 'plant_code' => '5F04', 'unit_name' => 'PKS Rimba Belian', 'product' => 'Inti Sawit', 'nilai_rp' => 50_000_000],
            ['year' => 2026, 'period' => 6, 'plant_code' => '5E13', 'unit_name' => 'Kebun Batulicin', 'product' => 'Minyak Sawit', 'nilai_rp' => 999_000_000],
        ]);
        // Baris "Penyesuaian atas nilai persediaan akhir" (kolom PERSEDIAAN AKHIR).
        DB::table('persediaan_penyesuaian')->insert([
            'year' => 2026, 'month' => 6,
            'plant_code' => 'Penyesuaian Nilai Akhir', 'product' => 'Penyesuaian Nilai Akhir',
            'transfer_masuk' => 0, 'transfer_keluar' => 0, 'susut' => 0,
            'sto_gr' => 0, 'sto_gi' => 0, 'nilai_akhir' => 7_000_000,
        ]);

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=6')->assertOk()->json();

        // = baris "Jumlah Persediaan" kolom NILAI PERSEDIAAN AKHIR tab KELAPA SAWIT.
        $this->assertEqualsWithDelta(257_000_000, $data['values']['persediaan_akhir']['ks'], 0.001);
        $this->assertEqualsWithDelta(
            app(\App\Http\Controllers\Api\PersediaanController::class)->nilaiAkhirJumlah(2026, 6, 'sawit'),
            $data['values']['persediaan_akhir']['ks'],
            0.001
        );
        // Karet dikosongkan: baris penyesuaian tidak terpisah per komoditi, mengisi
        // keduanya akan menghitungnya dua kali di kolom Jumlah.
        $this->assertEqualsWithDelta(0, $data['values']['persediaan_akhir']['kr'], 0.001);
    }

    public function test_biaya_usaha_dari_halaman_beban(): void
    {
        $this->seedPenjualan();

        $gl = fn (string $type, int $period, string $class, float $amount): array => [
            'report_type' => $type, 'document_number' => 'DOC-1',
            'posting_date' => sprintf('2026-%02d-10', $period), 'year' => 2026, 'period' => $period,
            'account' => '6', 'gl_account_desc' => 'Beban', 'profit_center' => '5R00000001',
            'profit_center_desc' => 'Kandir', 'cost_center' => 'CC', 'cost_element' => 'CE',
            'text' => 'Uji', 'amount' => $amount, 'class_code' => '00', 'class_desc' => $class,
        ];

        DB::table('beban_usaha_gl')->insert([
            // Beban Penjualan: kumulatif s.d Juni = 300 + 700 (Juli tidak ikut).
            $gl('PENJ', 5, 'Pelabuhan / EMKL', 300),
            $gl('PENJ', 6, 'Pelabuhan / EMKL', 700),
            $gl('PENJ', 7, 'Pelabuhan / EMKL', 9999),
            // Beban Administrasi: dipecah ke ADMI KS/KR memakai %Proporsi bulan itu.
            $gl('ADMIN', 5, 'Beban Konsultan', 1000),
            $gl('ADMIN', 6, 'Beban Konsultan', 2000),
        ]);
        // %Proporsi: Mei 60% sawit / 40% karet, Juni 75% / 25%.
        DB::table('beban_usaha_proporsi')->insert([
            ['year' => 2026, 'month' => 5, 'uraian' => 'ABS Sawit', 'total_nilai' => 100, 'nilai_proporsi' => 60],
            ['year' => 2026, 'month' => 5, 'uraian' => 'ABS Karet', 'total_nilai' => 100, 'nilai_proporsi' => 40],
            ['year' => 2026, 'month' => 6, 'uraian' => 'ABS Sawit', 'total_nilai' => 100, 'nilai_proporsi' => 75],
            ['year' => 2026, 'month' => 6, 'uraian' => 'ABS Karet', 'total_nilai' => 100, 'nilai_proporsi' => 25],
        ]);

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=6')->assertOk()->json();

        // Biaya Penjualan = baris "Jumlah" seksi Kelapa Sawit; seksi Karet kosong.
        // Biaya → dikirim negatif.
        $this->assertEqualsWithDelta(-1000, $data['values']['biaya_penjualan']['ks'], 0.001);
        $this->assertEqualsWithDelta(0, $data['values']['biaya_penjualan']['kr'], 0.001);

        // Administrasi Kandir = Σ (nilai bulan × %proporsi bulan itu):
        // ks = 1000×60% + 2000×75% = 2.100; kr = 1000×40% + 2000×25% = 900.
        $this->assertEqualsWithDelta(-2100, $data['values']['administrasi_kandir']['ks'], 0.001);
        $this->assertEqualsWithDelta(-900, $data['values']['administrasi_kandir']['kr'], 0.001);

        // …dan harus sama dengan yang dihitung halaman Beban itu sendiri.
        $beban = app(\App\Http\Controllers\Api\BebanUsahaDataController::class);
        $this->assertEqualsWithDelta(
            -$beban->bebanPenjualanSd(2026, 6)['ks'], $data['values']['biaya_penjualan']['ks'], 0.001
        );
        $this->assertEqualsWithDelta(
            -$beban->bebanAdministrasiSd(2026, 6)['ks'], $data['values']['administrasi_kandir']['ks'], 0.001
        );
    }

    public function test_baris_lm13_nol_bila_periode_tanpa_batch(): void
    {
        $this->seedPenjualan();

        // Juli 2026 punya data penjualan tetapi tidak punya batch LM13.
        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=7')->assertOk()->json();

        foreach (['penyusutan', 'biaya_produksi'] as $key) {
            $this->assertEqualsWithDelta(0, $data['values'][$key]['ks'], 0.001);
            $this->assertEqualsWithDelta(0, $data['values'][$key]['kr'], 0.001);
        }

        // Tidak ada kolom yang boleh dihitung Harga Pokok Penjualan-nya: tanpa
        // biaya produksi & penyusutan, totalnya hanya persediaan → menyesatkan.
        $this->assertSame([], $data['values']['hpp_kolom']);
    }

    public function test_tanpa_parameter_adopsi_periode_terbaru(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a')->assertOk()->json();

        // Periode terbaru = Juli 2026 → CPO 9.999 ikut terhitung.
        $this->assertSame(2026, $data['year']);
        $this->assertSame(7, $data['month']);
        $this->assertEqualsWithDelta(13419, $data['values']['lokal']['ks'], 0.001);
        $this->assertSame(['year' => 2026, 'month' => 7], $data['periods'][0]);
    }

    public function test_periode_tanpa_data_bernilai_nol(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2025&month=12')->assertOk()->json();

        $this->assertSame(2025, $data['year']);
        $this->assertEqualsWithDelta(0, $data['values']['lokal']['ks'], 0.001);
        $this->assertEqualsWithDelta(0, $data['values']['lokal']['kr'], 0.001);
    }

    public function test_tanpa_data_sama_sekali(): void
    {
        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a')->assertOk()->json();

        $this->assertSame([], $data['periods']);
        $this->assertNull($data['year']);
        $this->assertSame([], $data['values']);
    }

    public function test_butuh_otentikasi(): void
    {
        $this->getJson('/report-data/laba-rugi/lm27a')->assertStatus(401);
    }
}
