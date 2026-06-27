<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\DataPurgeService;
use App\Http\Controllers\Controller;
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

        $total = array_sum($counts);
        $detail = collect($counts)->map(fn ($n, $table) => "{$table}: {$n}")->implode(', ');

        return back()->with('status', "Hapus data [{$label}] ({$scope}) selesai: {$total} baris terhapus."
            .($detail !== '' ? " [{$detail}]" : ''));
    }
}
