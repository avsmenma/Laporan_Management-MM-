<?php

namespace App\Domain\Import;

use App\Domain\Import\Support\RawWorkbookImport;
use App\Models\Batch;
use App\Models\ImportUploadLog;
use App\Models\RefUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SpreadsheetImportService
{
    private array $knownUnitCodes = [];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'wbs' => 'DB WBS',
            'btl' => 'DB BTL',
            'pks_biaya' => 'Biaya Pabrik - Summary',
            'pks_produksi' => 'Produksi Pabrik - LM625Fxx',
            'alokasi_produksi' => 'Alokasi Produksi',
            'alokasi_areal' => 'Alokasi Areal',
            'budget_rkap' => 'Budget RKAP',
            'budget_rko' => 'Budget RKO',
            'realisasi_tahun_lalu' => 'Realisasi Tahun Lalu',
        ];
    }

    public function import(string $type, Batch $batch, UploadedFile|string $file, ?int $userId = null): ImportResult
    {
        abort_unless(array_key_exists($type, self::types()), 422, 'Jenis import tidak dikenal.');

        $this->knownUnitCodes = RefUnit::query()->pluck('type', 'code')->all();
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        $workbook = $this->readWorkbook($path);

        $result = DB::transaction(fn () => match ($type) {
            'wbs' => $this->importWbs($batch, $workbook),
            'btl' => $this->importBtl($batch, $workbook),
            'pks_biaya' => $this->importPksBiaya($batch, $workbook),
            'pks_produksi' => $this->importPksProduksi($batch, $workbook),
            'alokasi_produksi' => $this->importAlokasiProduksi($batch, $workbook),
            'alokasi_areal' => $this->importAlokasiAreal($batch, $workbook),
            'budget_rkap' => $this->importBudget($batch, $workbook, 'budget_rkap'),
            'budget_rko' => $this->importBudget($batch, $workbook, 'budget_rko'),
            'realisasi_tahun_lalu' => $this->importTahunLalu($batch, $workbook),
        });

        ImportUploadLog::query()->create([
            'batch_id' => $batch->id,
            'user_id' => $userId,
            'jenis' => $type,
            'filename' => $filename,
            'row_count' => $result->rowCount,
            'error_count' => $result->errorCount(),
            'errors' => $result->errors,
            'uploaded_at' => now(),
        ]);

        return $result;
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function readWorkbook(string $path): array
    {
        $arrays = Excel::toArray(new RawWorkbookImport(), $path);
        $sheetNames = IOFactory::load($path)->getSheetNames();

        return collect($arrays)
            ->mapWithKeys(fn (array $rows, int $index) => [$sheetNames[$index] ?? "Sheet {$index}" => $rows])
            ->all();
    }

    private function importWbs(Batch $batch, array $workbook): ImportResult
    {
        DB::table('db_wbs')->where('batch_id', $batch->id)->delete();

        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'DB WBS'), [
            'komoditi' => ['budidaya', 'komoditi', 'kode'],
            'plant_code' => ['plant', 'plantcode', 'kodeunit'],
            'period' => ['period', 'periode'],
            'aktivitas' => ['aktifitas', 'aktivitas', 'activity'],
            'job_name' => ['jobname', 'namapekerjaan'],
            'cost_element' => ['costelement', 'gl'],
            'cost_element_desc' => ['costelementdesc', 'costelementdescription', 'deskripsi'],
            'klasifikasi_code' => ['klasifikasi', 'klasifikasicode'],
            'nilai' => ['nilai', 'amount'],
            'fisik' => ['fisik'],
        ], ['plant_code', 'period', 'nilai']);

        $inserted = 0;
        foreach ($rows as $index => $row) {
            $komoditi = $this->komoditi($row['komoditi'] ?? null);
            $plantCode = $this->text($row['plant_code'] ?? null);

            if (! $this->isKnownUnit($plantCode) || ! in_array($komoditi, ['KS', 'KR'], true)) {
                $errors[] = "DB WBS baris {$index}: plant/komoditi tidak dikenal.";
                continue;
            }

            DB::table('db_wbs')->insert([
                'batch_id' => $batch->id,
                'komoditi' => $komoditi,
                'plant_code' => $plantCode,
                'period' => $this->int($row['period'] ?? $batch->month),
                'aktivitas' => $this->nullableText($row['aktivitas'] ?? null),
                'job_name' => $this->nullableText($row['job_name'] ?? null),
                'cost_element' => $this->nullableText($row['cost_element'] ?? null),
                'cost_element_desc' => $this->nullableText($row['cost_element_desc'] ?? null),
                'klasifikasi_code' => $this->nullableText($row['klasifikasi_code'] ?? null),
                'nilai' => $this->number($row['nilai'] ?? 0),
                'fisik' => $this->nullableNumber($row['fisik'] ?? null),
            ]);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importBtl(Batch $batch, array $workbook): ImportResult
    {
        DB::table('db_btl')->where('batch_id', $batch->id)->delete();

        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'DB BTL'), [
            'komoditi' => ['kode', 'komoditi', 'budidaya'],
            'plant_code' => ['plant', 'plantcode', 'kodeunit'],
            'unit_kerja' => ['unitkerja'],
            'period' => ['period', 'periode'],
            'kode_cc' => ['kodecc', 'costcenter', 'cc'],
            'co_object_name' => ['coobjectname', 'objectname'],
            'cost_element' => ['costelement', 'gl'],
            'cost_element_name' => ['costelementname'],
            'klasifikasi_code' => ['klasifikasi', 'klasifikasicode'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'period', 'nilai']);

        $inserted = 0;
        foreach ($rows as $index => $row) {
            $komoditi = $this->komoditi($row['komoditi'] ?? null);
            $plantCode = $this->text($row['plant_code'] ?? null);

            if (! $this->isKnownUnit($plantCode) || ! in_array($komoditi, ['KS', 'KR'], true)) {
                $errors[] = "DB BTL baris {$index}: plant/komoditi tidak dikenal.";
                continue;
            }

            DB::table('db_btl')->insert([
                'batch_id' => $batch->id,
                'komoditi' => $komoditi,
                'plant_code' => $plantCode,
                'unit_kerja' => $this->nullableText($row['unit_kerja'] ?? null),
                'period' => $this->int($row['period'] ?? $batch->month),
                'kode_cc' => $this->nullableText($row['kode_cc'] ?? null),
                'co_object_name' => $this->nullableText($row['co_object_name'] ?? null),
                'cost_element' => $this->nullableText($row['cost_element'] ?? null),
                'cost_element_name' => $this->nullableText($row['cost_element_name'] ?? null),
                'klasifikasi_code' => $this->nullableText($row['klasifikasi_code'] ?? null),
                'nilai' => $this->number($row['nilai'] ?? 0),
            ]);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importPksBiaya(Batch $batch, array $workbook): ImportResult
    {
        DB::table('pks_biaya')->where('batch_id', $batch->id)->delete();

        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'Summary'), [
            'plant_code' => ['plant', 'plantcode', 'pabrik'],
            'period' => ['period', 'periode'],
            'cost_center' => ['costcenter', 'costctr', 'cc'],
            'cost_element' => ['costelement', 'gl'],
            'klasifikasi_code' => ['klasifikasi', 'klasifikasicode'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'nilai']);

        $inserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? null);

            if (! $this->isKnownUnit($plantCode, 'PABRIK')) {
                $errors[] = "Summary baris {$index}: pabrik tidak dikenal.";
                continue;
            }

            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $this->int($row['period'] ?? $batch->month),
                'cost_center' => $this->nullableText($row['cost_center'] ?? null),
                'cost_element' => $this->nullableText($row['cost_element'] ?? null),
                'klasifikasi_code' => $this->nullableText($row['klasifikasi_code'] ?? null),
                'nilai' => $this->number($row['nilai'] ?? 0),
            ]);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importPksProduksi(Batch $batch, array $workbook): ImportResult
    {
        DB::table('pks_produksi')->where('batch_id', $batch->id)->delete();

        $sheetName = collect(array_keys($workbook))->first(fn (string $name) => str_starts_with(strtoupper($name), 'LM625F')) ?? 'LM625F01';
        [$rows, $errors] = $this->tableRows($this->sheet($workbook, $sheetName), [
            'plant_code' => ['plant', 'plantcode', 'pabrik'],
            'period' => ['period', 'periode'],
            'uraian' => ['uraian', 'keterangan'],
            'nilai_bi' => ['nilaibi', 'bulanini', 'bi'],
            'nilai_sd' => ['nilaisd', 'sdbulanini', 'sd'],
        ], ['uraian']);

        $inserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? $this->plantCodeFromLm625Sheet($sheetName));

            if (! $this->isKnownUnit($plantCode, 'PABRIK')) {
                $errors[] = "{$sheetName} baris {$index}: pabrik tidak dikenal.";
                continue;
            }

            DB::table('pks_produksi')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $this->int($row['period'] ?? $batch->month),
                'uraian' => $this->text($row['uraian'] ?? null),
                'nilai_bi' => $this->number($row['nilai_bi'] ?? 0),
                'nilai_sd' => $this->number($row['nilai_sd'] ?? 0),
            ]);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importAlokasiProduksi(Batch $batch, array $workbook): ImportResult
    {
        DB::table('alokasi_produksi')->where('batch_id', $batch->id)->delete();

        $rows = $this->sheet($workbook, 'Alokasi');
        $errors = [];

        if ($this->looksLikeAlokasiMatrix($rows)) {
            $records = $this->positionalAlokasiProduksi($batch, $rows);
        } else {
            [$tableRows, $errors] = $this->tableRows($rows, [
                'year' => ['year', 'tahun'],
                'month' => ['month', 'bulan'],
                'kebun_code' => ['kebuncode', 'kebun', 'plant'],
                'pabrik_code' => ['pabrikcode', 'pabrik'],
                'produk' => ['produk'],
                'jumlah' => ['jumlah', 'nilai'],
            ], ['kebun_code', 'produk', 'jumlah']);

            $records = [];
            foreach ($tableRows as $index => $row) {
                $records[] = [
                    'batch_id' => $batch->id,
                    'year' => $this->int($row['year'] ?? $batch->year),
                    'month' => $this->int($row['month'] ?? $batch->month),
                    'kebun_code' => $this->text($row['kebun_code'] ?? null),
                    'pabrik_code' => $this->nullableText($row['pabrik_code'] ?? null),
                    'produk' => $this->text($row['produk'] ?? null),
                    'jumlah' => $this->number($row['jumlah'] ?? 0),
                ];
            }
        }

        $inserted = 0;
        foreach ($records as $index => $record) {
            if (! $this->isKnownUnit($record['kebun_code'], 'KEBUN')) {
                $errors[] = "Alokasi produksi baris {$index}: kebun tidak dikenal.";
                continue;
            }

            DB::table('alokasi_produksi')->insert($record);
            $inserted++;
        }

        return new ImportResult($inserted, $errors);
    }

    private function importAlokasiAreal(Batch $batch, array $workbook): ImportResult
    {
        [$rows, $errors] = $this->tableRows($this->sheet($workbook, 'Alokasi'), [
            'year' => ['year', 'tahun'],
            'kebun_code' => ['kebuncode', 'kebun', 'plant'],
            'real_thn_lalu' => ['realthnlalu', 'realisasitahunlalu'],
            'real_thn_ini' => ['realthnini', 'realisasitahunini'],
            'rko' => ['rko'],
            'rkap' => ['rkap'],
        ], ['kebun_code']);

        $upserted = 0;
        foreach ($rows as $index => $row) {
            $kebunCode = $this->text($row['kebun_code'] ?? null);
            if (! $this->isKnownUnit($kebunCode, 'KEBUN')) {
                $errors[] = "Alokasi areal baris {$index}: kebun tidak dikenal.";
                continue;
            }

            DB::table('alokasi_areal')->updateOrInsert(
                ['year' => $this->int($row['year'] ?? $batch->year), 'kebun_code' => $kebunCode],
                [
                    'real_thn_lalu' => $this->number($row['real_thn_lalu'] ?? 0),
                    'real_thn_ini' => $this->number($row['real_thn_ini'] ?? 0),
                    'rko' => $this->number($row['rko'] ?? 0),
                    'rkap' => $this->number($row['rkap'] ?? 0),
                ],
            );
            $upserted++;
        }

        return new ImportResult($upserted, $errors);
    }

    private function importBudget(Batch $batch, array $workbook, string $table): ImportResult
    {
        [$rows, $errors] = $this->tableRows($this->firstSheet($workbook), [
            'year' => ['year', 'tahun'],
            'komoditi' => ['komoditi', 'budidaya'],
            'plant_code' => ['plant', 'plantcode', 'kodeunit'],
            'report_type' => ['reporttype', 'laporan'],
            'kode' => ['kode', 'kodebaris'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'report_type', 'kode', 'nilai']);

        $upserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? null);
            $reportType = $this->reportType($row['report_type'] ?? null);

            if (! $this->isKnownUnit($plantCode) || $reportType === null) {
                $errors[] = "{$table} baris {$index}: plant/report_type tidak valid.";
                continue;
            }

            DB::table($table)->updateOrInsert(
                [
                    'year' => $this->int($row['year'] ?? $batch->year),
                    'komoditi' => $this->nullableKomoditi($row['komoditi'] ?? null),
                    'plant_code' => $plantCode,
                    'report_type' => $reportType,
                    'kode' => $this->text($row['kode'] ?? null),
                ],
                ['nilai' => $this->number($row['nilai'] ?? 0) * 1000],
            );
            $upserted++;
        }

        return new ImportResult($upserted, $errors);
    }

    private function importTahunLalu(Batch $batch, array $workbook): ImportResult
    {
        [$rows, $errors] = $this->tableRows($this->firstSheet($workbook), [
            'year' => ['year', 'tahun'],
            'komoditi' => ['komoditi', 'budidaya'],
            'plant_code' => ['plant', 'plantcode', 'kodeunit'],
            'report_type' => ['reporttype', 'laporan'],
            'kode' => ['kode', 'kodebaris'],
            'period' => ['period', 'periode'],
            'nilai' => ['nilai', 'amount'],
        ], ['plant_code', 'report_type', 'kode', 'period', 'nilai']);

        $upserted = 0;
        foreach ($rows as $index => $row) {
            $plantCode = $this->text($row['plant_code'] ?? null);
            $reportType = $this->reportType($row['report_type'] ?? null);

            if (! $this->isKnownUnit($plantCode) || $reportType === null) {
                $errors[] = "Tahun lalu baris {$index}: plant/report_type tidak valid.";
                continue;
            }

            DB::table('realisasi_tahun_lalu')->updateOrInsert(
                [
                    'year' => $this->int($row['year'] ?? $batch->year - 1),
                    'komoditi' => $this->nullableKomoditi($row['komoditi'] ?? null),
                    'plant_code' => $plantCode,
                    'report_type' => $reportType,
                    'kode' => $this->text($row['kode'] ?? null),
                    'period' => $this->int($row['period'] ?? $batch->month),
                ],
                ['nilai' => $this->number($row['nilai'] ?? 0)],
            );
            $upserted++;
        }

        return new ImportResult($upserted, $errors);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function tableRows(array $sheetRows, array $aliases, array $required): array
    {
        $errors = [];
        $headerIndex = $this->findHeaderIndex($sheetRows, $aliases, $required);
        if ($headerIndex === null) {
            return [[], ['Header kolom tidak ditemukan.']];
        }

        $headers = $this->headers($sheetRows[$headerIndex], $aliases);
        $rows = [];
        foreach (array_slice($sheetRows, $headerIndex + 1) as $offset => $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $row = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $row[$field] = $values[$column] ?? null;
                }
            }

            if ($this->requiredMissing($row, $required)) {
                continue;
            }

            $rows[$headerIndex + 2 + $offset] = $row;
        }

        return [$rows, $errors];
    }

    private function findHeaderIndex(array $rows, array $aliases, array $required): ?int
    {
        foreach (array_slice($rows, 0, 40, true) as $index => $row) {
            $fields = array_filter($this->headers($row, $aliases));
            $missing = array_diff($required, $fields);
            if ($missing === []) {
                return $index;
            }
        }

        return null;
    }

    private function headers(array $row, array $aliases): array
    {
        return collect($row)->map(function (mixed $value) use ($aliases): ?string {
            $normalized = $this->normalize($value);
            foreach ($aliases as $field => $names) {
                if (in_array($normalized, $names, true)) {
                    return $field;
                }
            }

            return null;
        })->all();
    }

    private function requiredMissing(array $row, array $required): bool
    {
        foreach ($required as $field) {
            if (($row[$field] ?? null) !== null && trim((string) $row[$field]) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sheet(array $workbook, string $name): array
    {
        foreach ($workbook as $sheetName => $rows) {
            if (strcasecmp($sheetName, $name) === 0) {
                return $rows;
            }
        }

        return $this->firstSheet($workbook);
    }

    private function firstSheet(array $workbook): array
    {
        return reset($workbook) ?: [];
    }

    private function positionalAlokasiProduksi(Batch $batch, array $rows): array
    {
        $records = [];
        $pabrikCodes = [];
        foreach (($rows[1] ?? []) as $column => $value) {
            $code = $this->nullableText($value);
            if ($code !== null && preg_match('/^5F\d{2}$/', $code)) {
                $pabrikCodes[$column] = $code;
            }
        }

        foreach ($rows as $row) {
            $kebunCode = $this->nullableText($row[1] ?? null);
            $produk = $this->nullableText($row[13] ?? null);

            if ($kebunCode === null || $produk === null) {
                continue;
            }

            foreach ($pabrikCodes as $column => $pabrikCode) {
                $jumlah = $this->nullableNumber($row[$column] ?? null);
                if ($jumlah === null || abs($jumlah) < 0.00001) {
                    continue;
                }

                $records[] = [
                    'batch_id' => $batch->id,
                    'year' => $batch->year,
                    'month' => $this->int($row[12] ?? $batch->month),
                    'kebun_code' => $kebunCode,
                    'pabrik_code' => $pabrikCode,
                    'produk' => $produk,
                    'jumlah' => $jumlah,
                ];
            }
        }

        return $records;
    }

    private function looksLikeAlokasiMatrix(array $rows): bool
    {
        return collect($rows[1] ?? [])->contains(fn (mixed $value) => is_string($value) && preg_match('/^5F\d{2}$/', trim($value)));
    }

    private function plantCodeFromLm625Sheet(string $sheetName): ?string
    {
        if (preg_match('/LM625F(\\d{2})/i', $sheetName, $matches)) {
            return '5F'.$matches[1];
        }

        return null;
    }

    private function isKnownUnit(?string $code, ?string $type = null): bool
    {
        if ($code === null || ! isset($this->knownUnitCodes[$code])) {
            return false;
        }

        return $type === null || $this->knownUnitCodes[$code] === $type;
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value))) ?? '';
    }

    private function komoditi(mixed $value): ?string
    {
        $text = strtoupper(trim((string) $value));

        return match (true) {
            str_contains($text, 'KS'), str_contains($text, 'SAWIT') => 'KS',
            str_contains($text, 'KR'), str_contains($text, 'KARET') => 'KR',
            default => null,
        };
    }

    private function nullableKomoditi(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : $this->komoditi($value);
    }

    private function reportType(mixed $value): ?string
    {
        $text = strtoupper(trim((string) $value));

        return in_array($text, ['LM14', 'LM13', 'LM16'], true) ? $text : null;
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function int(mixed $value): int
    {
        return (int) $this->number($value);
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->number($value);
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return 0.0;
        }

        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);

        return (float) $text;
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn (mixed $value) => trim((string) $value) !== '')->isEmpty();
    }
}
