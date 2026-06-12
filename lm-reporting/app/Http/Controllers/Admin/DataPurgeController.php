<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\DataPurgeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataPurgeController extends Controller
{
    public function purge(Request $request, DataPurgeService $service): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:month,year,all'],
            'year' => ['required_unless:mode,all', 'nullable', 'integer', 'between:2000,2100'],
            'month' => ['required_if:mode,month', 'nullable', 'integer', 'between:1,12'],
            'konfirmasi' => ['required', 'in:HAPUS'],
        ]);

        $counts = match ($data['mode']) {
            'month' => $service->purgeByMonth((int) $data['year'], (int) $data['month']),
            'year' => $service->purgeByYear((int) $data['year']),
            'all' => $service->purgeAll(),
        };

        $scope = match ($data['mode']) {
            'month' => "bulan {$data['month']}/{$data['year']}",
            'year' => "tahun {$data['year']}",
            'all' => 'SEMUA data',
        };

        $total = array_sum($counts);
        $detail = collect($counts)->map(fn ($n, $table) => "{$table}: {$n}")->implode(', ');

        return back()->with('status', "Hapus data ({$scope}) selesai: {$total} baris terhapus."
            .($detail !== '' ? " [{$detail}]" : ''));
    }
}
