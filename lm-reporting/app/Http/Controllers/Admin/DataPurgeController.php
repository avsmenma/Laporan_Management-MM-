<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\DataPurgeService;
use App\Http\Controllers\Controller;
use App\Jobs\RegenerateReports;
use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataPurgeController extends Controller
{
    public function index(): View
    {
        return view('admin.purge', [
            'years' => Batch::query()->select('year')->distinct()->orderByDesc('year')->pluck('year'),
            'batches' => Batch::query()->orderByDesc('year')->orderByDesc('month')->get(),
            'targets' => DataPurgeService::targets(),
        ]);
    }

    public function purge(Request $request, DataPurgeService $service): RedirectResponse
    {
        $targetKeys = array_keys(DataPurgeService::targets());

        $data = $request->validate([
            // target kosong / 'all_tables' = hapus semua tabel sesuai cakupan (perilaku lama).
            'target' => ['nullable', 'in:all_tables,'.implode(',', $targetKeys)],
            'mode' => ['required', 'in:month,year,all'],
            'year' => ['required_unless:mode,all', 'nullable', 'integer', 'between:2000,2100'],
            'month' => ['required_if:mode,month', 'nullable', 'integer', 'between:1,12'],
            'konfirmasi' => ['required', 'in:HAPUS'],
        ]);

        $target = $data['target'] ?? null;
        $year = isset($data['year']) ? (int) $data['year'] : null;
        $month = isset($data['month']) ? (int) $data['month'] : null;

        $perTarget = $target !== null && $target !== '' && $target !== 'all_tables';

        $counts = $perTarget
            ? $service->purgeTarget($target, $data['mode'], $year, $month)
            : match ($data['mode']) {
                'month' => $service->purgeByMonth((int) $year, (int) $month),
                'year' => $service->purgeByYear((int) $year),
                'all' => $service->purgeAll(),
            };

        $scope = match ($data['mode']) {
            'month' => "bulan {$month}/{$year}",
            'year' => "tahun {$year}",
            'all' => 'semua periode',
        };

        $label = $perTarget
            ? (DataPurgeService::targets()[$target]['group'].' — '.DataPurgeService::targets()[$target]['label'])
            : 'Semua tabel';

        // Regenerasi laporan otomatis setelah hapus SELEKTIF (batch tetap ada). Hapus
        // global (per bulan/tahun/semua) menghapus batch sekaligus → tak perlu regenerasi.
        // Target "laporan" dikecualikan: user memang ingin laporan terhapus, bukan dibangun ulang.
        $regenInfo = '';
        if ($perTarget && $target !== 'laporan') {
            $batchIds = $this->scopedBatchIds($data['mode'], $year);
            if ($batchIds !== []) {
                Batch::query()->whereIn('id', $batchIds)->update(['needs_regenerate' => true]);
                RegenerateReports::dispatch($batchIds);
                $regenInfo = ' Regenerasi laporan ('.count($batchIds).' batch) dijadwalkan otomatis.';
            }
        }

        $total = array_sum($counts);
        $detail = collect($counts)->map(fn ($n, $table) => "{$table}: {$n}")->implode(', ');

        return back()->with('status', "Hapus data [{$label}] ({$scope}) selesai: {$total} baris terhapus."
            .($detail !== '' ? " [{$detail}]" : '').$regenInfo);
    }

    /**
     * Batch yang perlu diregenerasi setelah hapus selektif. Selalu mencakup seluruh
     * batch dalam cakupan TAHUN (mode month pun) karena sumber per-tahun (anggaran,
     * tahun lalu, alokasi) memengaruhi semua bulan; aman & cukup murah (sedikit batch).
     *
     * @return array<int, int>
     */
    private function scopedBatchIds(string $mode, ?int $year): array
    {
        $q = Batch::query();
        if ($mode !== 'all') {
            if ($year === null) {
                return [];
            }
            $q->where('year', $year);
        }

        return $q->pluck('id')->all();
    }
}
