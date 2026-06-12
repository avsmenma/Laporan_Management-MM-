@extends('layouts.app')

@section('title', 'Hapus Data')

@section('content')
<div>
    @if (session('status'))
        <div class="alert alert-ok" style="margin-bottom:18px">{{ session('status') }}</div>
    @endif

    <section class="panel" style="border-color:#e6b8b3">
        <div class="panel-head"><span class="panel-title" style="color:#b42318">⚠️ Hapus Data (Admin)</span></div>
        <div class="panel-body" x-data="{ mode: 'month', konfirmasi: '' }">
            <p class="field-hint" style="margin-top:0;margin-bottom:16px">
                Menghapus data laporan &amp; impor sesuai cakupan beserta batch periodenya. Tindakan ini <b>permanen</b> dan tidak bisa dibatalkan.
            </p>
            <form method="POST" action="{{ route('data.purge') }}"
                  @submit="if (!confirm('Yakin menghapus data? Tindakan ini permanen dan tidak bisa dibatalkan.')) $event.preventDefault()">
                @csrf
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="field" style="margin-bottom:0">
                        <label>Cakupan</label>
                        <select name="mode" x-model="mode" class="field-control">
                            <option value="month">Per Bulan</option>
                            <option value="year">Per Tahun</option>
                            <option value="all">Semua Data</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0" x-show="mode !== 'all'">
                        <label>Tahun</label>
                        <select name="year" class="field-control">
                            @forelse ($years as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @empty
                                <option value="{{ now()->year }}">{{ now()->year }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0" x-show="mode === 'month'">
                        <label>Bulan</label>
                        <select name="month" class="field-control">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}" @selected($m === 5)>{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>Ketik <b>HAPUS</b> untuk konfirmasi</label>
                        <input name="konfirmasi" x-model="konfirmasi" class="field-control" placeholder="HAPUS" autocomplete="off">
                    </div>
                </div>
                <button type="submit" class="btn" style="margin-top:16px;background:#b42318;color:#fff;border-color:#b42318"
                        :disabled="konfirmasi !== 'HAPUS'" :style="konfirmasi !== 'HAPUS' ? 'opacity:.5;cursor:not-allowed' : ''">
                    Hapus Data
                </button>
                <p class="field-hint" style="margin-top:12px">
                    <b>Per Bulan</b> menghapus data &amp; batch periode tsb (anggaran tahunan RKAP/RKO/areal tidak ikut terhapus).
                    <b>Per Tahun</b> menghapus seluruh data, batch, dan anggaran tahun tsb.
                    <b>Semua Data</b> mengosongkan seluruh batch &amp; data.
                </p>
            </form>
        </div>
    </section>

    <section class="panel" style="overflow:hidden;margin-top:20px">
        <div class="panel-head"><span class="panel-title">Data Saat Ini</span></div>
        <table class="htable">
            <thead>
                <tr><th>Kode</th><th>Tahun</th><th>Bulan</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td class="file-cell">{{ $batch->code }}</td>
                        <td class="mono">{{ $batch->year }}</td>
                        <td class="mono">{{ $batch->month }}</td>
                        <td><span class="pill pill-idle"><span class="dot"></span>{{ $batch->status }}</span></td>
                    </tr>
                @empty
                    <tr><td class="empty-cell" colspan="4">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
