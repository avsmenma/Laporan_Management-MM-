<?php

namespace Tests\Feature;

use App\Domain\Report\RbbPivotService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman RBB (Rincian PNL) — bentuk & angka harus mengikuti pivot "Report I."
 * workbook Beban Pokok & Usaha: baris 3 tingkat, kolom Klasifikasi × Segmen dinamis,
 * sel tanpa posting dibiarkan kosong.
 */
class RbbApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function glRow(string $klas, string $klas2, string $jenis, string $segmen, float $amount, array $extra = []): array
    {
        return array_merge([
            'year' => 2026,
            'period' => 1,
            'posting_date' => '2026-01-31',
            'account' => '51100401',
            'gl_account_desc' => 'Biaya Gaji dan Tunjangan',
            'profit_center' => '5E01000001',
            'cost_center' => '5E01BT01KS',
            'amount' => $amount,
            'klasifikasi' => $klas,
            'klasifikasi_2' => $klas2,
            'jenis_beban' => $jenis,
            'segmen' => $segmen,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);
    }

    private function seedGl(): void
    {
        DB::table('rbb_gl')->insert([
            // Penjualan: hanya Sawit & Karet (blok ini tak punya Pabrik/Regional).
            $this->glRow('1. Penjualan Produk', 'a. Penjualan', '', '1. Sawit', -1000, ['account' => '41100000', 'gl_account_desc' => 'Pendapatan Usaha Sawit']),
            $this->glRow('1. Penjualan Produk', 'a. Penjualan', '', '2. Karet', -400, ['account' => '41100006', 'gl_account_desc' => 'Pendapatan Usaha Karet']),
            // Beban Pokok: dua Jenis Beban, salah satunya dua akun (uji subtotal tingkat 2).
            $this->glRow('2. Beban Pokok Penjualan', 'b. Overhead', 'Biaya Keamanan', '1. Sawit', 300),
            $this->glRow('2. Beban Pokok Penjualan', 'b. Overhead', 'Biaya Keamanan', '1. Sawit', 25, ['account' => '51100608', 'gl_account_desc' => 'Biaya Keamanan']),
            $this->glRow('2. Beban Pokok Penjualan', 'b. Overhead', 'Biaya Keamanan', '3. Pabrik', 75),
            $this->glRow('2. Beban Pokok Penjualan', 'b. Overhead', 'Biaya Asistensi', '1. Sawit', 100),
            $this->glRow('2. Beban Pokok Penjualan', 'i. Penyusutan', 'Penyusutan', '2. Karet', 50),
            // Periode lain: tidak boleh ikut terhitung.
            $this->glRow('2. Beban Pokok Penjualan', 'b. Overhead', 'Biaya Keamanan', '1. Sawit', 9999, ['period' => 2]),
        ]);
        app(RbbPivotService::class)->generateAll(2026);
    }

    /** @return array<string, mixed> */
    private function ambil(int $year = 2026, int $month = 1): array
    {
        return $this->actingAs($this->actingViewer())
            ->getJson("/report-data/laba-rugi/rbb?year={$year}&month={$month}")
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<string, mixed>
     */
    private function cari(array $baris, string $uraian): array
    {
        foreach ($baris as $r) {
            if ($r['uraian'] === $uraian) {
                return $r;
            }
        }
        $this->fail("Baris '{$uraian}' tidak ada di laporan.");
    }

    public function test_blok_kolom_hanya_segmen_yang_ada_datanya(): void
    {
        $this->seedGl();
        $data = $this->ambil();

        $this->assertSame([
            ['nama' => '1. Penjualan Produk', 'segmen' => ['1. Sawit', '2. Karet']],
            ['nama' => '2. Beban Pokok Penjualan', 'segmen' => ['1. Sawit', '2. Karet', '3. Pabrik']],
        ], $data['blok']);
    }

    public function test_baris_tiga_tingkat_berurut_dan_subtotal_dari_rinciannya(): void
    {
        $this->seedGl();
        $baris = $this->ambil()['baris'];

        // Urutan pivot: Klasifikasi 2 alfabetis, tiap Jenis Beban di bawah induknya.
        $urut = array_map(fn ($r) => $r['level'].':'.$r['uraian'], $baris);
        $this->assertSame([
            '1:a. Penjualan',
            '2:',
            '3:41100000',
            '3:41100006',
            '1:b. Overhead',
            '2:Biaya Asistensi',
            '3:51100401',
            '2:Biaya Keamanan',
            '3:51100401',
            '3:51100608',
            '1:i. Penyusutan',
            '2:Penyusutan',
            '3:51100401',
            '0:Grand Total',
        ], $urut);

        $overhead = $this->cari($baris, 'b. Overhead');
        $this->assertSame(425.0, (float) $overhead['nilai']['2. Beban Pokok Penjualan|1. Sawit']);
        $this->assertSame(75.0, (float) $overhead['nilai']['2. Beban Pokok Penjualan|3. Pabrik']);
        $this->assertSame(500.0, (float) $overhead['nilai']['2. Beban Pokok Penjualan|__total']);
        $this->assertSame(500.0, (float) $overhead['nilai']['__grand']);

        $keamanan = $this->cari($baris, 'Biaya Keamanan');
        $this->assertSame(325.0, (float) $keamanan['nilai']['2. Beban Pokok Penjualan|1. Sawit']);
    }

    public function test_grand_total_dan_sel_kosong_untuk_kombinasi_tanpa_posting(): void
    {
        $this->seedGl();
        $baris = $this->ambil()['baris'];

        $total = $this->cari($baris, 'Grand Total');
        $this->assertSame(-1000.0, (float) $total['nilai']['1. Penjualan Produk|1. Sawit']);
        $this->assertSame(-1400.0, (float) $total['nilai']['1. Penjualan Produk|__total']);
        $this->assertSame(425.0, (float) $total['nilai']['2. Beban Pokok Penjualan|1. Sawit']);
        $this->assertSame(-850.0, (float) $total['nilai']['__grand']);

        // Penjualan tidak punya posting Pabrik → kuncinya TIDAK ada (sel dibiarkan
        // kosong di halaman), bukan tersimpan sebagai 0.
        $this->assertArrayNotHasKey('1. Penjualan Produk|3. Pabrik', $total['nilai']);
        $penyusutan = $this->cari($baris, 'i. Penyusutan');
        $this->assertArrayNotHasKey('2. Beban Pokok Penjualan|1. Sawit', $penyusutan['nilai']);
    }

    public function test_periode_lain_tidak_ikut_dan_periode_kosong_aman(): void
    {
        $this->seedGl();

        $februari = $this->ambil(2026, 2);
        $keamanan = $this->cari($februari['baris'], 'Biaya Keamanan');
        $this->assertSame(9999.0, (float) $keamanan['nilai']['2. Beban Pokok Penjualan|1. Sawit']);

        $kosong = $this->ambil(2026, 7);
        $this->assertFalse($kosong['ada_data']);
        $this->assertSame([], $kosong['blok']);
        $this->assertSame([], $kosong['baris']);
    }

    public function test_tanpa_parameter_memakai_periode_terbaru(): void
    {
        $this->seedGl();

        $data = $this->actingAs($this->actingViewer())
            ->getJson('/report-data/laba-rugi/rbb')
            ->assertOk()
            ->json();

        $this->assertSame(['year' => 2026, 'month' => 2, 'label' => 'FEBRUARI 2026'], $data['periode']);
    }

    public function test_label_tak_terpetakan_diletakkan_paling_bawah(): void
    {
        DB::table('rbb_gl')->insert([
            $this->glRow('2. Beban Pokok Penjualan', 'h. Beban Pengolahan', '#N/A', '3. Pabrik', 0),
            $this->glRow('2. Beban Pokok Penjualan', 'h. Beban Pengolahan', 'a. Gaji Karyawan', '3. Pabrik', 10),
            $this->glRow('2. Beban Pokok Penjualan', 'h. Beban Pengolahan', 'ST. KERNEL', '3. Pabrik', 20),
        ]);
        app(RbbPivotService::class)->generateAll(2026);

        $jenis = array_values(array_map(
            fn ($r) => $r['uraian'],
            array_filter($this->ambil()['baris'], fn ($r) => $r['level'] === 2)
        ));
        $this->assertSame(['a. Gaji Karyawan', 'ST. KERNEL', '#N/A'], $jenis);
    }

    public function test_halaman_rbb_terbuka(): void
    {
        $this->actingAs($this->actingViewer())
            ->get('/laba-rugi/rbb')
            ->assertOk()
            ->assertSee('RINCIAN PNL', false);
    }
}
