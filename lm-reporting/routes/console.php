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
