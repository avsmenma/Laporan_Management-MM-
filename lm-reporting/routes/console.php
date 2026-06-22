<?php

use App\Domain\Import\SpreadsheetImportService;
use App\Domain\Report\Lm13Service;
use App\Domain\Report\Lm14Service;
use App\Domain\Report\Lm16Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('lm:import-raw {--type=} {--file=} {--year=} {--month=} {--batch=}', function (SpreadsheetImportService $service): int {
    $type = strtolower((string) $this->option('type'));
    $file = (string) $this->option('file');

    if (! array_key_exists($type, SpreadsheetImportService::types())) {
        $this->error('Opsi --type wajib salah satu: '.implode(', ', array_keys(SpreadsheetImportService::types())).'.');

        return 1;
    }

    if ($file === '' || ! is_file($file)) {
        $this->error("Berkas tidak ditemukan: {$file}");

        return 1;
    }

    // Batch ditentukan via --batch (id/kode) atau pasangan --year & --month (dibuat bila belum ada).
    $batchInput = (string) $this->option('batch');
    if ($batchInput !== '') {
        $batch = Batch::query()
            ->where('id', is_numeric($batchInput) ? (int) $batchInput : 0)
            ->orWhere('code', $batchInput)
            ->first();
    } else {
        $year = (int) $this->option('year');
        $month = (int) $this->option('month');
        if ($year < 2000 || $month < 1 || $month > 12) {
            $this->error('Sertakan --batch, atau --year (>=2000) dan --month (1-12).');

            return 1;
        }

        $batch = Batch::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['code' => sprintf('Batch #%04d-%02d', $year, $month), 'status' => 'draft'],
        );
    }

    if (! $batch) {
        $this->error("Batch {$batchInput} tidak ditemukan.");

        return 1;
    }

    $label = SpreadsheetImportService::types()[$type];
    $this->info("Mengimpor {$label} dari ".basename($file)." ke batch {$batch->code} (id {$batch->id})…");

    $result = $service->import($type, $batch, $file, null);

    $this->info("Selesai: {$result->rowCount} baris tersimpan, {$result->errorCount()} error.");
    foreach (array_slice($result->errors, 0, 10) as $error) {
        $this->warn('  - '.$error);
    }

    return 0;
})->purpose('Impor file mentah SAP (wbs/ohc/gc) ke tabel staging secara streaming.');

Artisan::command('alokasi:import-areal {--file=} {--year=}', function (): int {
    // Impor blok "III. Areal" dari sheet "Alokasi" (Luas Area Kebun per unit) ke
    // tabel alokasi_areal. Header sheet ini ("Real Tahun Lalu" dst) tidak cocok
    // dengan importer generik, jadi dibaca posisional: deteksi baris header lalu
    // ambil kolom Unit Kebun + 4 kolom nilai sampai "Grand Total"/baris kosong.
    $file = (string) $this->option('file');
    $year = (int) $this->option('year');

    if ($file === '' || ! is_file($file)) {
        $this->error("Berkas tidak ditemukan: {$file}");

        return 1;
    }
    if ($year < 2000) {
        $this->error('Opsi --year wajib (>=2000), mis. --year=2026.');

        return 1;
    }

    $norm = fn ($v) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
    $number = function ($v): float {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return 0.0;
        }
        // Hilangkan pemisah ribuan; dukung format Indonesia (1.730,63) & Inggris (1730.63).
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $v);
    };

    $knownKebun = RefUnit::query()->where('type', 'KEBUN')->pluck('code')->map(fn ($c) => strtoupper($c))->all();
    $knownKebun = array_flip($knownKebun);

    $reader = new XlsxReader();
    $reader->open($file);

    $headerCols = null;       // ['kebun'=>idx,'real_thn_lalu'=>idx,...]
    $upserted = 0;
    $skipped = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() !== 'Alokasi') {
            continue;
        }

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->toArray();

            if ($headerCols === null) {
                // Cari baris header areal: ada "Unit Kebun" + "Real Tahun Lalu".
                $map = [];
                foreach ($cells as $idx => $cell) {
                    $map[$norm($cell)] = $idx;
                }
                if (isset($map['unit kebun'], $map['real tahun lalu'], $map['real tahun ini'])) {
                    $headerCols = [
                        'kebun' => $map['unit kebun'],
                        'real_thn_lalu' => $map['real tahun lalu'],
                        'real_thn_ini' => $map['real tahun ini'],
                        'rko' => $map['rko tw'] ?? ($map['rko'] ?? null),
                        'rkap' => $map['rkap'] ?? null,
                    ];
                }

                continue;
            }

            $kebun = strtoupper(trim((string) ($cells[$headerCols['kebun']] ?? '')));
            if ($kebun === '' || $norm($kebun) === 'grand total') {
                break; // akhir blok areal
            }
            if (! isset($knownKebun[$kebun])) {
                $skipped[] = $kebun;

                continue;
            }

            DB::table('alokasi_areal')->updateOrInsert(
                ['year' => $year, 'kebun_code' => $kebun],
                [
                    'real_thn_lalu' => $number($cells[$headerCols['real_thn_lalu']] ?? 0),
                    'real_thn_ini' => $number($cells[$headerCols['real_thn_ini']] ?? 0),
                    'rko' => $headerCols['rko'] !== null ? $number($cells[$headerCols['rko']] ?? 0) : 0,
                    'rkap' => $headerCols['rkap'] !== null ? $number($cells[$headerCols['rkap']] ?? 0) : 0,
                ],
            );
            $upserted++;
        }

        break;
    }
    $reader->close();

    if ($headerCols === null) {
        $this->error('Header "III. Areal" (Unit Kebun / Real Tahun Lalu / ...) tidak ditemukan di sheet Alokasi.');

        return 1;
    }

    $this->info("Selesai: {$upserted} unit kebun di-upsert ke alokasi_areal untuk tahun {$year}.");
    if ($skipped !== []) {
        $this->warn('Dilewati (bukan kebun dikenal): '.implode(', ', array_unique($skipped)));
    }

    return 0;
})->purpose('Impor Luas Area Kebun (blok III. Areal) dari sheet Alokasi ke alokasi_areal.');

Artisan::command('alokasi:import-produksi {--file=} {--year=}', function (): int {
    // Impor matriks produksi (TBS/CPO/Kernel per pabrik) dari sheet "Alokasi" ke
    // alokasi_produksi. Sel di workbook acuan adalah formula IMPORTRANGE yang diekspor
    // Google Sheets sebagai =IFERROR(__xludf.DUMMYFUNCTION("..."),<NILAI>) — nilai riil
    // tersimpan sebagai argumen fallback IFERROR, jadi diambil dari situ.
    $file = (string) $this->option('file');
    $year = (int) $this->option('year');

    if ($file === '' || ! is_file($file)) {
        $this->error("Berkas tidak ditemukan: {$file}");

        return 1;
    }
    if ($year < 2000) {
        $this->error('Opsi --year wajib (>=2000), mis. --year=2026.');

        return 1;
    }

    // Ambil nilai fallback (argumen terakhir IFERROR) dari formula DUMMYFUNCTION;
    // untuk sel literal (tanpa '=') kembalikan apa adanya.
    $fallback = function ($cell): string {
        $s = trim((string) $cell);
        if ($s === '' || $s[0] !== '=') {
            return $s;
        }
        if (preg_match('/,\s*"(.*)"\)\s*$/s', $s, $m)) {
            return $m[1]; // fallback berupa teks
        }
        if (preg_match('/,\s*(-?\d+(?:\.\d+)?)\)\s*$/s', $s, $m)) {
            return $m[1]; // fallback berupa angka
        }

        return '';
    };
    $num = fn ($v) => (float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $v));

    $knownKebun = array_flip(RefUnit::query()->where('type', 'KEBUN')->pluck('code')->map(fn ($c) => strtoupper($c))->all());
    $skipProduk = ['', 'produk', 'keterangan', 'bulan'];

    $reader = new XlsxReader();
    $reader->open($file);

    $matrix = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() !== 'Alokasi') {
            continue;
        }
        foreach ($sheet->getRowIterator() as $row) {
            $matrix[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    if (count($matrix) < 3) {
        $this->error('Sheet Alokasi tidak ditemukan / kosong.');

        return 1;
    }

    // Kolom pabrik (kode 5Fxx) ada di baris ke-2 matriks.
    $pabrikCols = [];
    foreach (($matrix[1] ?? []) as $idx => $val) {
        $code = strtoupper(trim((string) $val));
        if (preg_match('/^5F\d{2}$/', $code)) {
            $pabrikCols[$idx] = $code;
        }
    }
    if ($pabrikCols === []) {
        $this->error('Kolom pabrik (5Fxx) tidak terdeteksi di baris ke-2 sheet Alokasi.');

        return 1;
    }

    $records = [];
    $months = [];
    foreach (array_slice($matrix, 2) as $cells) {
        $kebun = strtoupper(trim((string) ($cells[1] ?? '')));
        $produk = trim($fallback($cells[13] ?? ''));
        $month = (int) $num($fallback($cells[12] ?? ''));

        if (! isset($knownKebun[$kebun]) || $month < 1 || $month > 12) {
            continue;
        }
        if (in_array(strtolower($produk), $skipProduk, true)) {
            continue;
        }

        foreach ($pabrikCols as $idx => $pabrikCode) {
            $jumlah = $num($fallback($cells[$idx] ?? ''));
            if (abs($jumlah) < 0.00001) {
                continue;
            }
            $records[] = [
                'batch_id' => null,
                'year' => $year,
                'month' => $month,
                'kebun_code' => $kebun,
                'pabrik_code' => $pabrikCode,
                'produk' => $produk,
                'jumlah' => $jumlah,
            ];
            $months[$month] = true;
        }
    }

    // Idempoten: hapus data tahun+bulan yang akan diisi, lalu sisipkan. batch_id
    // ditautkan ke batch (year, month) bila ada (alokasi dipakai lintas batch via year+month).
    $batchByMonth = [];
    foreach (array_keys($months) as $m) {
        $batchByMonth[$m] = optional(Batch::query()->where('year', $year)->where('month', $m)->first())->id;
    }
    foreach ($records as &$rec) {
        $rec['batch_id'] = $batchByMonth[$rec['month']] ?? null;
    }
    unset($rec);

    DB::transaction(function () use ($records, $year, $months): void {
        DB::table('alokasi_produksi')->where('year', $year)->whereIn('month', array_keys($months))->delete();
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('alokasi_produksi')->insert($chunk);
        }
    });

    $this->info('Selesai: '.count($records).' baris produksi diimpor (bulan: '.implode(',', array_keys($months)).", tahun {$year}).");

    return 0;
})->purpose('Impor matriks produksi (TBS/CPO/Kernel per pabrik) dari sheet Alokasi ke alokasi_produksi.');

Artisan::command('report:generate {--type=} {--batch=} {--unit=} {--komoditi=KS}', function (Lm13Service $lm13Service, Lm14Service $lm14Service, Lm16Service $lm16Service): int {
    $type = strtoupper((string) $this->option('type'));
    $batchInput = (string) $this->option('batch');
    $unitCode = $this->option('unit');
    $komoditi = strtoupper((string) $this->option('komoditi'));

    if (! in_array($type, ['LM13', 'LM14', 'LM16'], true)) {
        $this->error('Command report:generate mendukung --type=LM13, LM14, atau LM16.');

        return 1;
    }

    if ($batchInput === '') {
        $this->error('Opsi --batch wajib diisi dengan id atau kode batch.');

        return 1;
    }

    $batch = Batch::query()
        ->where('id', is_numeric($batchInput) ? (int) $batchInput : 0)
        ->orWhere('code', $batchInput)
        ->first();

    if (! $batch) {
        $this->error("Batch {$batchInput} tidak ditemukan.");

        return 1;
    }

    // LM16 untuk PABRIK (tidak perlu komoditi filter di whereHas)
    if ($type === 'LM16') {
        $units = RefUnit::query()
            ->where('type', 'PABRIK')
            ->when($unitCode, fn ($query) => $query->where('code', $unitCode))
            ->when($komoditi, fn ($query) => $query->where('komoditi', $komoditi))
            ->orderBy('code')
            ->get();

        if ($units->isEmpty()) {
            $this->error('Tidak ada unit pabrik yang cocok dengan filter command.');

            return 1;
        }

        foreach ($units as $unit) {
            $rows = $lm16Service->generate($batch, $unit);
            $this->info("{$type} {$unit->code}: {$rows->count()} baris dimaterialisasi.");
        }

        return 0;
    }

    // LM13 & LM14 untuk KEBUN
    $units = RefUnit::query()
        ->where('type', 'KEBUN')
        ->when($unitCode, fn ($query) => $query->where('code', $unitCode))
        ->whereHas('komoditis', fn ($query) => $query->where('komoditi', $komoditi))
        ->orderBy('code')
        ->get();

    if ($units->isEmpty()) {
        $this->error('Tidak ada unit kebun yang cocok dengan filter command.');

        return 1;
    }

    foreach ($units as $unit) {
        $rows = $type === 'LM13'
            ? $lm13Service->generate($batch, $unit, $komoditi)
            : $lm14Service->generate($batch, $unit, $komoditi);

        $this->info("{$type} {$unit->code} {$komoditi}: {$rows->count()} baris dimaterialisasi.");
    }

    return 0;
})->purpose('Generate materialized report LM.');

Artisan::command('lm:tahunlalu-wbs {--dir=} {--file=*} {--year=2025}', function (): int {
    // Mengisi tabel realisasi_tahun_lalu (report_type=LM14) dari ekstrak WBS mentah tahun lalu.
    // Aturan cocok IDENTIK dengan mesin tahun berjalan untuk baris source=WBS:
    //   nilai = SUM(Value) per (komoditi, plant, period, Aktifitas), dengan Aktifitas = kode baris.
    // Baris source=BTL (gaji staf, depresiasi 511*, overhead BT01..) TIDAK tercakup di sini —
    // sumbernya OHC (db_ohc), diisi terpisah saat ekstrak OHC tahun lalu tersedia.
    $year = (int) $this->option('year');
    if ($year < 2000 || $year > 2100) {
        $this->error('Opsi --year tidak wajar: '.$year);

        return 1;
    }

    // Kumpulkan daftar berkas dari --file (boleh berulang) dan/atau --dir (pindai *.xlsx).
    $files = array_values(array_filter((array) $this->option('file'), fn ($f) => is_string($f) && $f !== ''));
    $dir = (string) $this->option('dir');
    if ($dir !== '') {
        if (! is_dir($dir)) {
            $this->error("Direktori tidak ditemukan: {$dir}");

            return 1;
        }
        foreach (glob(rtrim($dir, "/\\").DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $f) {
            $files[] = $f;
        }
    }
    $files = array_values(array_unique($files));
    if ($files === []) {
        $this->error('Tidak ada berkas. Pakai --dir=<folder> atau --file=<path.xlsx> (boleh berulang).');

        return 1;
    }
    foreach ($files as $f) {
        if (! is_file($f)) {
            $this->error("Berkas tidak ditemukan: {$f}");

            return 1;
        }
    }

    // Himpunan kode baris LM14 ber-source WBS (format Aktifitas, mis. 41-01). Hanya Aktifitas
    // yang termasuk himpunan ini yang disimpan sebagai realisasi; sisanya dicatat untuk audit.
    $validKode = DB::table('lm_template_row')
        ->where('report_type', 'LM14')
        ->where('source', 'WBS')
        ->whereNotNull('kode')
        ->where('kode', '<>', '')
        ->pluck('kode')
        ->flip();

    if ($validKode->isEmpty()) {
        $this->error('Tidak ada kode LM14 source=WBS di lm_template_row. Seed template dulu.');

        return 1;
    }

    // Indeks kolom 0-based mengikuti urutan kolom file DB WBS (sama dengan WBS_COLUMNS importer).
    $COL_PLANT = 1;
    $COL_KOMODITI = 7;
    $COL_PERIOD = 8;
    $COL_AKTIFITAS = 15;
    $COL_VALUE = 24;
    $COL_SOURCE = 46;

    // Ekstrak WBS mentah memuat KEDUA sisi settlement: Pengirim (pembawa biaya) & Penerima
    // (lawan-jurnal) yang saling meniadakan hingga ~0 bila dijumlah polos. Feed db_wbs_raw
    // tahun berjalan hanya berisi baris Source='Pengirim' (terbukti: jumlah baris cocok-kode
    // sisi Pengirim = total real_bulan_ini WBS pada report_lm14). Tiru persis: ambil hanya
    // 'Pengirim' (mencakup cost element primer 5xxxxxx & sekunder 9xxxxxx), tanpa filter lain.
    $SOURCE_KEEP = 'Pengirim';

    // Nama kolom db_wbs_tahun_lalu sesuai urutan kolom file (sama dengan WBS_COLUMNS importer).
    // Baris mentah cocok-kode sisi Pengirim disimpan agar drill-down "Real Thn Lalu" bisa
    // mem-pivot & menampilkan rincian mentah persis seperti kolom Real Bln Ini/Bln Lalu.
    $WBS_COLS = [
        'company_code', 'plant', 'plant_desc', 'divisi_afdeling', 'blok', 'status_blok', 'tahun_tanam',
        'komoditi', 'period', 'project', 'wbs', 'wbs_desc', 'fase', 'group_aktifitas', 'group_desc',
        'aktifitas', 'job_name', 'hierarchy_area', 'cost_center', 'cc_desc', 'partner_cctr', 'partner_cctr_desc',
        'cost_element', 'cost_element_desc', 'value', 'currency', 'material', 'mat_desc', 'qty', 'uom',
        'object_num', 'object_type', 'profit_center', 'value_type', 'reference_procedure', 'order_no',
        'order_type', 'order_category', 'order_desc', 'hectare_planted', 'co_business_transaction',
        'mapping_cogm', 'klasifikasi', 'kode', 'pekerjaan_pb712_ii', 'pekerjaan_pb7_i', 'source', 'keterangan',
    ];
    $NUMERIC_COLS = ['value' => true, 'qty' => true, 'period' => true, 'hectare_planted' => true];

    // Ganti baris mentah tahun lalu untuk tahun ini (idempotent). Insert dilakukan streaming
    // per-chunk di dalam loop agar memori tetap konstan (file besar, ratusan ribu baris).
    DB::table('db_wbs_tahun_lalu')->where('year', $year)->delete();
    $rawBuf = [];
    $rawInserted = 0;

    $agg = [];        // "komoditi|plant|period|kode" => nilai
    $unmatched = [];  // aktifitas => nilai (di luar himpunan kode, hanya untuk audit)
    $periods = [];
    $rowsRead = 0;
    $rowsUsed = 0;

    foreach ($files as $file) {
        $reader = new XlsxReader;
        $reader->open($file);
        $sheet = $row = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $first = true;
                foreach ($sheet->getRowIterator() as $row) {
                    if ($first) {
                        $first = false;

                        continue; // baris header
                    }
                    $c = $row->toArray();
                    $rowsRead++;

                    $komoditi = trim((string) ($c[$COL_KOMODITI] ?? ''));
                    if ($komoditi !== 'KS' && $komoditi !== 'KR') {
                        continue; // mesin hanya menghitung KS/KR; baris komoditi kosong diabaikan
                    }
                    $plant = trim((string) ($c[$COL_PLANT] ?? ''));
                    $period = (int) ($c[$COL_PERIOD] ?? 0);
                    $aktifitas = trim((string) ($c[$COL_AKTIFITAS] ?? ''));
                    $source = trim((string) ($c[$COL_SOURCE] ?? ''));
                    $rawVal = $c[$COL_VALUE] ?? null;
                    $nilai = is_numeric($rawVal) ? (float) $rawVal : 0.0;
                    if ($plant === '' || $period < 1 || $period > 12 || $aktifitas === '') {
                        continue;
                    }
                    if ($source !== $SOURCE_KEEP) {
                        continue; // hanya sisi Pengirim — sama dengan feed db_wbs_raw produksi
                    }

                    $periods[$period] = true;
                    if (! $validKode->has($aktifitas)) {
                        $unmatched[$aktifitas] = ($unmatched[$aktifitas] ?? 0.0) + $nilai;

                        continue;
                    }

                    $key = $komoditi.'|'.$plant.'|'.$period.'|'.$aktifitas;
                    $agg[$key] = ($agg[$key] ?? 0.0) + $nilai;
                    $rowsUsed++;

                    // Simpan baris mentah (seluruh kolom) untuk drill-down.
                    $rec = ['year' => $year, 'plant_code' => $plant];
                    foreach ($WBS_COLS as $idx => $col) {
                        $v = $c[$idx] ?? null;
                        if ($v === null || (is_string($v) && trim($v) === '')) {
                            $rec[$col] = null;
                        } elseif (isset($NUMERIC_COLS[$col])) {
                            $rec[$col] = is_numeric($v) ? (float) $v : null;
                        } else {
                            $t = trim((string) $v);
                            $rec[$col] = $t === '' ? null : mb_substr($t, 0, 250);
                        }
                    }
                    $rawBuf[] = $rec;
                    if (count($rawBuf) >= 500) {
                        DB::table('db_wbs_tahun_lalu')->insert($rawBuf);
                        $rawInserted += count($rawBuf);
                        $rawBuf = [];
                    }
                }

                break; // hanya sheet pertama
            }
        } finally {
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();
        }
        $this->info('Selesai baca: '.basename($file));
    }

    if ($rawBuf !== []) {
        DB::table('db_wbs_tahun_lalu')->insert($rawBuf);
        $rawInserted += count($rawBuf);
        $rawBuf = [];
    }

    // Susun record realisasi (buang nilai ~0 agar tabel ramping).
    $records = [];
    $totalPerPeriod = [];
    foreach ($agg as $key => $nilai) {
        if (abs($nilai) < 0.00001) {
            continue;
        }
        [$komoditi, $plant, $period, $kode] = explode('|', $key);
        $records[] = [
            'year' => $year,
            'komoditi' => $komoditi,
            'plant_code' => $plant,
            'report_type' => 'LM14',
            'kode' => $kode,
            'period' => (int) $period,
            'nilai' => round($nilai, 2),
        ];
        $totalPerPeriod[$period] = ($totalPerPeriod[$period] ?? 0.0) + $nilai;
    }

    // Ganti hanya cakupan (year, LM14, period yang terbaca) — idempotent, aman diulang.
    DB::transaction(function () use ($year, $periods, $records): void {
        DB::table('realisasi_tahun_lalu')
            ->where('year', $year)
            ->where('report_type', 'LM14')
            ->whereIn('period', array_keys($periods))
            ->delete();
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('realisasi_tahun_lalu')->insert($chunk);
        }
    });

    $this->newLine();
    $this->info("== Ringkasan impor tahun lalu (WBS) — tahun {$year} ==");
    $this->line('Berkas diproses : '.count($files));
    $this->line('Baris dibaca    : '.number_format($rowsRead));
    $this->line('Baris terpakai  : '.number_format($rowsUsed).' (Aktifitas cocok kode LM14 WBS, komoditi KS/KR)');
    $this->line('Record disimpan : '.number_format(count($records)).' (period: '.implode(',', array_keys($periods)).')');
    $this->line('Baris mentah    : '.number_format($rawInserted).' disimpan ke db_wbs_tahun_lalu (untuk drill-down)');

    ksort($totalPerPeriod);
    $this->newLine();
    $this->line('Total nilai per period:');
    foreach ($totalPerPeriod as $p => $t) {
        $this->line(sprintf('  period %-2d : %s', $p, number_format($t, 2)));
    }

    if ($unmatched !== []) {
        arsort($unmatched);
        $this->newLine();
        $this->line('15 Aktifitas TIDAK termasuk kode LM14 WBS (audit; mayoritas baris BTL/alokasi/assessment):');
        $i = 0;
        foreach ($unmatched as $akt => $t) {
            $this->line(sprintf('  %-12s : %s', $akt, number_format($t, 2)));
            if (++$i >= 15) {
                break;
            }
        }
    }

    $this->newLine();
    $this->warn('Catatan: baris LM14 source=BTL (gaji staf, depresiasi 511*, overhead BT01..) belum terisi — menunggu ekstrak OHC tahun lalu.');

    return 0;
})->purpose('Isi realisasi_tahun_lalu (LM14) dari ekstrak WBS mentah tahun lalu; cocok Aktifitas=kode, SUM(Value).');

Artisan::command('budget:import-test {--dir=} {--year=2026}', function (): int {
    // Impor RKO/RKAP (DATA TESTING) dari folder docs/rko_rkap ke budget_rko & budget_rkap.
    //
    // Karakter data sumber (lihat docs/ANALISIS_KESIAPAN_RKO_RKAP.md):
    //   - File tidak memisahkan RKO vs RKAP (satu kolom "Nilai") → nilai disalin ke
    //     KEDUA tabel (budget_rko & budget_rkap) sesuai permintaan tampilan.
    //   - Tidak ada kolom year/report_type → year dari --year, report_type=LM14.
    //   - Budget bersifat TAHUNAN (tabel budget tanpa kolom periode), jadi periode di
    //     file diabaikan; nilai dijumlah per (komoditi, plant, kode).
    //   - Nilai disimpan RUPIAH PENUH (mengikuti skala kolom realisasi db_wbs/db_ohc),
    //     TANPA ×1000.
    // Pemetaan kode (kolom E file) ke kode baris template (lm_template_row.kode):
    //   - BKU : "Aktifitas" → kode WBS (mis. 41-01, 99-01). Hanya unit KEBUN.
    //   - OHC : "Kode CC"   → kode BTL (BT01..BT16 = db_ohc.lock). Pabrik (5F) dilewati.
    //   - GC  : "Kode GC" (AP/AR) tidak punya baris template LM14 → dilewati & dicatat.
    $year = (int) $this->option('year');
    if ($year < 2000 || $year > 2100) {
        $this->error('Opsi --year tidak wajar: '.$year);

        return 1;
    }

    $dir = (string) $this->option('dir');
    if ($dir === '') {
        $dir = dirname(base_path()).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'rko_rkap';
    }
    if (! is_dir($dir)) {
        $this->error("Direktori tidak ditemukan: {$dir}");

        return 1;
    }

    // Peta kode unit → tipe (KEBUN/PABRIK) untuk memilah tujuan & menolak unit asing.
    $unitType = RefUnit::query()->get(['code', 'type'])
        ->mapWithKeys(fn ($u) => [strtoupper((string) $u->code) => $u->type])->all();

    // Himpunan baris template LM14 valid sebagai "KOMODITI|kode" (cocok per komoditi).
    $lm14 = DB::table('lm_template_row')
        ->where('report_type', 'LM14')->whereNotNull('kode')->where('kode', '<>', '')
        ->get(['komoditi', 'kode'])
        ->mapWithKeys(fn ($r) => [strtoupper((string) $r->komoditi).'|'.$r->kode => true])->all();
    if ($lm14 === []) {
        $this->error('lm_template_row LM14 kosong. Seed template dulu.');

        return 1;
    }

    $num = function ($v): float {
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
        // Dukung format Indonesia (1.234,56) & Inggris (1234.56).
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $v);
    };

    $find = function (string $needle) use ($dir): ?string {
        foreach (glob(rtrim($dir, "/\\").DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $f) {
            $b = basename($f);
            if (str_starts_with($b, '~$')) {
                continue; // lewati berkas kunci Excel
            }
            if (stripos($b, $needle) !== false) {
                return $f;
            }
        }

        return null;
    };

    $load = function (string $file): array {
        $out = [];
        $reader = new XlsxReader();
        $reader->open($file);
        foreach ($reader->getSheetIterator() as $sheet) {
            $first = true;
            foreach ($sheet->getRowIterator() as $row) {
                if ($first) { // lewati baris header
                    $first = false;

                    continue;
                }
                $out[] = $row->toArray();
            }

            break; // hanya sheet pertama
        }
        $reader->close();

        return $out;
    };

    // Indeks kolom 0-based SAMA untuk BKU & OHC: A=komoditi, B=plant, D=period, E=kode,
    // F=object_name (Job Name / CO Object Name), G=cost_element, H=cost_element_desc,
    // I=klasifikasi, J=nilai, K=fisik (BKU saja).
    [$C_KOM, $C_PLANT, $C_KODE, $C_NILAI] = [0, 1, 4, 9];
    [$C_PERIOD, $C_OBJ, $C_CE, $C_CEDESC, $C_KLAS, $C_FISIK] = [3, 5, 6, 7, 8, 10];

    // Potong & rapikan nilai teks (null bila kosong) sesuai panjang kolom budget_source.
    $str = function ($v, int $len): ?string {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : mb_substr($t, 0, $len);
    };

    $acc = [];     // "KOMODITI|PLANT|kode" => nilai (agregat)
    $rawSrc = [];  // baris mentah per-line (budget_source) untuk drill-down
    $stats = [
        'bku_ok' => 0, 'bku_skip_kode' => 0, 'bku_skip_unit' => 0,
        'ohc_ok' => 0, 'ohc_skip_pabrik' => 0, 'ohc_skip_kode' => 0, 'ohc_skip_unit' => 0,
        'gc_rows' => 0, 'gc_nilai' => 0.0,
    ];

    // --- BKU: Aktifitas → kode WBS (hanya KEBUN) ---
    $bku = $find('BKU');
    if ($bku === null) {
        $this->warn('File BKU tidak ditemukan (lewati).');
    } else {
        foreach ($load($bku) as $c) {
            $kom = strtoupper(trim((string) ($c[$C_KOM] ?? '')));
            $plant = strtoupper(trim((string) ($c[$C_PLANT] ?? '')));
            $kode = trim((string) ($c[$C_KODE] ?? ''));
            if ($kom === '' || $plant === '' || $kode === '') {
                continue;
            }
            if (($unitType[$plant] ?? null) !== 'KEBUN') {
                $stats['bku_skip_unit']++;

                continue;
            }
            if (! isset($lm14[$kom.'|'.$kode])) {
                $stats['bku_skip_kode']++;

                continue;
            }
            $nilai = $num($c[$C_NILAI] ?? 0);
            $k = $kom.'|'.$plant.'|'.$kode;
            $acc[$k] = ($acc[$k] ?? 0) + $nilai;
            $stats['bku_ok']++;
            $rawSrc[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => 'BKU',
                'period' => is_numeric($c[$C_PERIOD] ?? null) ? (int) $c[$C_PERIOD] : null,
                'object_name' => $str($c[$C_OBJ] ?? null, 250),
                'cost_element' => $str($c[$C_CE] ?? null, 40),
                'cost_element_desc' => $str($c[$C_CEDESC] ?? null, 250),
                'klasifikasi' => $str($c[$C_KLAS] ?? null, 60),
                'nilai' => round($nilai, 2),
                'fisik' => is_numeric($c[$C_FISIK] ?? null) ? (float) $c[$C_FISIK] : null,
            ];
        }
    }

    // --- OHC: Kode CC (BT01..) → kode BTL; pabrik (5F) dilewati ---
    $ohc = $find('OHC');
    if ($ohc === null) {
        $this->warn('File OHC tidak ditemukan (lewati).');
    } else {
        foreach ($load($ohc) as $c) {
            $kom = strtoupper(trim((string) ($c[$C_KOM] ?? '')));
            $plant = strtoupper(trim((string) ($c[$C_PLANT] ?? '')));
            $kode = trim((string) ($c[$C_KODE] ?? ''));
            if ($kom === '' || $plant === '' || $kode === '') {
                continue;
            }
            $type = $unitType[$plant] ?? null;
            if ($type === 'PABRIK') {
                $stats['ohc_skip_pabrik']++;

                continue;
            }
            if ($type !== 'KEBUN') {
                $stats['ohc_skip_unit']++;

                continue;
            }
            if (! isset($lm14[$kom.'|'.$kode])) {
                $stats['ohc_skip_kode']++;

                continue;
            }
            $nilai = $num($c[$C_NILAI] ?? 0);
            $k = $kom.'|'.$plant.'|'.$kode;
            $acc[$k] = ($acc[$k] ?? 0) + $nilai;
            $stats['ohc_ok']++;
            $rawSrc[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => 'OHC',
                'period' => is_numeric($c[$C_PERIOD] ?? null) ? (int) $c[$C_PERIOD] : null,
                'object_name' => $str($c[$C_OBJ] ?? null, 250),
                'cost_element' => $str($c[$C_CE] ?? null, 40),
                'cost_element_desc' => $str($c[$C_CEDESC] ?? null, 250),
                'klasifikasi' => $str($c[$C_KLAS] ?? null, 60),
                'nilai' => round($nilai, 2),
                'fisik' => null,
            ];
        }
    }

    // --- GC: tidak ada baris template LM14 → hanya dicatat ---
    $gc = $find('GC');
    if ($gc !== null) {
        foreach ($load($gc) as $c) {
            $kode = trim((string) ($c[$C_KODE] ?? ''));
            if ($kode === '') {
                continue;
            }
            $stats['gc_rows']++;
            $stats['gc_nilai'] += $num($c[$C_NILAI] ?? 0);
        }
    }

    // Tulis ke budget_rko & budget_rkap (nilai sama). Hapus dulu LM14 tahun ini
    // agar command bisa dijalankan ulang (idempoten untuk data testing).
    $rows = [];
    foreach ($acc as $key => $nilai) {
        [$kom, $plant, $kode] = explode('|', $key, 3);
        $rows[] = [
            'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
            'report_type' => 'LM14', 'kode' => $kode, 'nilai' => round($nilai, 2),
        ];
    }

    DB::transaction(function () use ($rows, $rawSrc, $year): void {
        DB::table('budget_rko')->where('year', $year)->where('report_type', 'LM14')->delete();
        DB::table('budget_rkap')->where('year', $year)->where('report_type', 'LM14')->delete();
        DB::table('budget_source')->where('year', $year)->where('report_type', 'LM14')->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('budget_rko')->insert($chunk);
            DB::table('budget_rkap')->insert($chunk);
        }
        foreach (array_chunk($rawSrc, 500) as $chunk) {
            DB::table('budget_source')->insert($chunk);
        }
    });

    $totalNilai = array_sum(array_column($rows, 'nilai'));
    $this->info("Selesai. {$year}: ".count($rows).' baris budget → budget_rko & budget_rkap (nilai identik).');
    $this->line('  Total nilai budget LM14 : '.number_format($totalNilai, 2));
    $this->line('  Baris mentah disimpan   : '.number_format(count($rawSrc)).' → budget_source (untuk drill-down RKO/RKAP).');
    $this->line("  BKU  : {$stats['bku_ok']} masuk · {$stats['bku_skip_kode']} kode di luar LM14 · {$stats['bku_skip_unit']} unit non-kebun");
    $this->line("  OHC  : {$stats['ohc_ok']} masuk · {$stats['ohc_skip_pabrik']} baris pabrik (5F) dilewati · {$stats['ohc_skip_kode']} kode di luar LM14");
    $this->warn('  GC   : '.$stats['gc_rows'].' baris ('.number_format($stats['gc_nilai'], 2).') DILEWATI — tidak ada baris template LM14 untuk kode GC (AP/AR).');
    $this->newLine();
    $this->warn('Jalankan ulang report:generate --type=LM14 agar kolom RKO/RKAP termaterialisasi ke report_lm14.');

    return 0;
})->purpose('Impor RKO/RKAP testing (docs/rko_rkap) ke budget_rko & budget_rkap; BKU=Aktifitas, OHC=Kode CC, GC dilewati.');
