@extends('layouts.app')

@section('title', 'Database')

@push('styles')
<style>
    .db-controls { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
    .db-field { display: flex; flex-direction: column; gap: 6px; }
    .db-field label { font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-500); }
    .db-field select, .db-field input {
        height: 38px; padding: 0 12px; border: 1px solid var(--line-strong); border-radius: 8px;
        font-family: inherit; font-size: 13px; color: var(--ink-900); background: var(--surface);
    }
    .db-field select:focus, .db-field input:focus { outline: none; border-color: var(--g-600); box-shadow: 0 0 0 3px var(--g-50); }
    .db-tablewrap {
        overflow: auto; max-height: calc(100vh - 330px); border: 1px solid var(--line); border-radius: 10px; background: var(--surface);
    }
    .db-table { border-collapse: separate; border-spacing: 0; font-size: 12px; width: max-content; min-width: 100%; }
    .db-table th {
        position: sticky; top: 0; z-index: 2; text-align: left; white-space: nowrap; padding: 9px 12px;
        font-size: 10.5px; letter-spacing: .03em; text-transform: uppercase; color: var(--ink-600); font-weight: 700;
        background: var(--surface-2); border-bottom: 1px solid var(--line-strong); border-right: 1px solid var(--line);
    }
    .db-table th.db-idx { left: 0; z-index: 3; width: 52px; text-align: right; color: var(--ink-400); }
    .db-table td {
        padding: 7px 12px; white-space: nowrap; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line);
        color: var(--ink-800); font-family: var(--font-mono); max-width: 360px; overflow: hidden; text-overflow: ellipsis;
    }
    .db-table td.db-idx {
        position: sticky; left: 0; z-index: 1; text-align: right; color: var(--ink-400);
        background: var(--surface-2); font-family: var(--font-mono);
    }
    .db-table tbody tr:hover td { background: var(--g-50); }
    .db-table tbody tr:hover td.db-idx { background: var(--g-100); }
    .db-null { color: var(--ink-400); font-style: italic; }
    .db-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; }
    .db-pageinfo { font-size: 12.5px; color: var(--ink-600); }
    .db-pager { display: flex; align-items: center; gap: 6px; }
    .db-pager button {
        height: 32px; min-width: 34px; padding: 0 11px; border: 1px solid var(--line-strong); background: var(--surface);
        color: var(--ink-700); border-radius: 7px; cursor: pointer; font-size: 12.5px; font-weight: 600; font-family: inherit;
    }
    .db-pager button:hover:not(:disabled) { border-color: var(--g-500); color: var(--g-700); background: var(--g-50); }
    .db-pager button:disabled { opacity: .45; cursor: not-allowed; }
    .db-pager input { width: 62px; height: 32px; text-align: center; border: 1px solid var(--line-strong); border-radius: 7px; font-family: inherit; font-size: 12.5px; }
    .db-state { padding: 40px 16px; text-align: center; color: var(--ink-500); font-size: 13px; }
    .db-badge { font-family: var(--font-mono); font-size: 11.5px; color: var(--ink-500); }
</style>
@endpush

@section('content')
<div x-data="databaseViewer()" x-init="init()">
    <section class="panel">
        <div class="panel-head">
            <span class="panel-title">🗃️ Database — Penjelajah Tabel</span>
            <span class="db-badge" x-show="table" x-cloak>
                <span x-text="table"></span> · <span x-text="fmtNum(pagination.total)"></span> baris
            </span>
        </div>
        <div class="panel-body">
            <p class="field-hint" style="margin-top:0;margin-bottom:16px">
                Lihat isi tabel database tanpa membuka MySQL. Data dimuat per halaman dari server (read-only),
                jadi tabel besar pun tidak membuat halaman berat.
            </p>

            <div class="db-controls">
                <div class="db-field">
                    <label>Tabel</label>
                    <select x-model="table" @change="onTableChange()">
                        @foreach ($tables as $t)
                            <option value="{{ $t['name'] }}">{{ $t['name'] }} (~{{ number_format($t['rows'], 0, ',', '.') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="db-field">
                    <label>Filter kolom</label>
                    <select x-model="filter.column">
                        <option value="">— tidak ada —</option>
                        <template x-for="col in columns" :key="col">
                            <option :value="col" x-text="col"></option>
                        </template>
                    </select>
                </div>
                <div class="db-field">
                    <label>Cara</label>
                    <select x-model="filter.op">
                        <option value="contains">mengandung</option>
                        <option value="eq">sama persis</option>
                    </select>
                </div>
                <div class="db-field">
                    <label>Nilai</label>
                    <input type="text" x-model="filter.value" @keydown.enter="applyFilter()" placeholder="ketik lalu Enter…">
                </div>
                <div class="db-field">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-primary" @click="applyFilter()">Terapkan</button>
                        <button class="btn" @click="resetFilter()" x-show="filter.column || filter.value">Reset</button>
                    </div>
                </div>
                <div class="db-field" style="margin-left:auto">
                    <label>Baris / halaman</label>
                    <select x-model.number="perPage" @change="changePerPage()">
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:16px">
                <div class="db-tablewrap" x-show="!loading && rows.length">
                    <table class="db-table">
                        <thead>
                            <tr>
                                <th class="db-idx">#</th>
                                <template x-for="col in columns" :key="col">
                                    <th x-text="col"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, ri) in rows" :key="ri">
                                <tr>
                                    <td class="db-idx" x-text="pagination.from + ri"></td>
                                    <template x-for="(cell, ci) in row" :key="ci">
                                        <td :title="cell">
                                            <template x-if="cell === null"><span class="db-null">NULL</span></template>
                                            <template x-if="cell !== null"><span x-text="cell"></span></template>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="db-state" x-show="loading">Memuat data…</div>
                <div class="db-state" x-show="!loading && error" x-text="error" style="color:var(--err)"></div>
                <div class="db-state" x-show="!loading && !error && !rows.length">Tidak ada baris yang cocok.</div>
            </div>

            <div class="db-foot" x-show="!loading && !error">
                <div class="db-pageinfo">
                    Menampilkan <b x-text="fmtNum(pagination.from)"></b>–<b x-text="fmtNum(pagination.to)"></b>
                    dari <b x-text="fmtNum(pagination.total)"></b> baris
                </div>
                <div class="db-pager">
                    <button @click="goto(1)" :disabled="pagination.page <= 1">«</button>
                    <button @click="goto(pagination.page - 1)" :disabled="pagination.page <= 1">‹ Sebelumnya</button>
                    <input type="number" min="1" :max="pagination.last_page" x-model.number="pageInput"
                           @keydown.enter="goto(pageInput)">
                    <span style="font-size:12.5px;color:var(--ink-500)">/ <span x-text="fmtNum(pagination.last_page)"></span></span>
                    <button @click="goto(pagination.page + 1)" :disabled="pagination.page >= pagination.last_page">Berikutnya ›</button>
                    <button @click="goto(pagination.last_page)" :disabled="pagination.page >= pagination.last_page">»</button>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
function databaseViewer() {
    return {
        table: @json($tables[0]['name'] ?? ''),
        columns: [],
        rows: [],
        loading: false,
        error: null,
        perPage: 50,
        pageInput: 1,
        filter: { column: '', op: 'contains', value: '' },
        pagination: { total: 0, per_page: 50, page: 1, last_page: 1, from: 0, to: 0 },

        init() {
            if (this.table) this.load(1, true);
        },
        // Ganti tabel/filter/ukuran halaman → hitung ulang total (count=1).
        onTableChange() {
            this.filter = { column: '', op: 'contains', value: '' };
            this.load(1, true);
        },
        applyFilter() { this.load(1, true); },
        resetFilter() {
            this.filter = { column: '', op: 'contains', value: '' };
            this.load(1, true);
        },
        changePerPage() { this.load(1, true); },
        // Navigasi antar-halaman → tidak menghitung COUNT lagi (cepat).
        goto(page) {
            page = Number(page) || 1;
            if (page < 1) page = 1;
            if (this.pagination.last_page && page > this.pagination.last_page) page = this.pagination.last_page;
            this.load(page, false);
        },
        async load(page, withCount) {
            this.loading = true;
            this.error = null;
            try {
                const params = new URLSearchParams({
                    table: this.table,
                    page: page,
                    per_page: this.perPage,
                    count: withCount ? 1 : 0,
                });
                if (this.filter.column && this.filter.value !== '') {
                    params.set('column', this.filter.column);
                    params.set('op', this.filter.op);
                    params.set('value', this.filter.value);
                }
                const res = await fetch(`{{ route('database.data') }}?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    throw new Error(body.message || `Gagal memuat (HTTP ${res.status}).`);
                }
                const data = await res.json();
                this.columns = data.columns;
                this.rows = data.rows;
                const p = data.pagination;
                // Saat count dilewati, pertahankan total & last_page yang sudah diketahui.
                if (p.total === null) {
                    p.total = this.pagination.total;
                    p.last_page = this.pagination.last_page;
                }
                this.pagination = p;
                this.pageInput = p.page;
            } catch (e) {
                this.error = e.message;
                this.rows = [];
            } finally {
                this.loading = false;
            }
        },
        fmtNum(n) {
            return new Intl.NumberFormat('id-ID').format(Number(n) || 0);
        },
    };
}
</script>
@endpush
