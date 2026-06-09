<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BatchController extends Controller
{
    public function index(): View
    {
        return view('master.batches.index', [
            'batches' => Batch::query()->orderByDesc('year')->orderByDesc('month')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'status' => ['required', 'in:draft,final,locked'],
        ]);

        Batch::query()->updateOrCreate(
            ['year' => $data['year'], 'month' => $data['month']],
            [
                'code' => sprintf('Batch #%04d-%02d', $data['year'], $data['month']),
                'status' => $data['status'],
                'processed_at' => now(),
            ],
        );

        return back()->with('status', 'Batch berhasil disimpan.');
    }
}
