@extends('layouts.app')

@section('title', 'Laporan Investasi Kebun')
@section('unit-label', 'Unit Kebun: ')

@section('content')
<div x-data="kebunInvestasiApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Komoditas</label>
                <select class="filter-select" x-model="filters.komoditi" disabled>
                    <option value="KS">Kelapa Sawit</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model="filters.year" @change="onYearChange()">
                    <option value="">- Pilih Tahun -</option>
                    <template x-for="y in availableYears()" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Periode (Bulan)</label>
                <select class="filter-select" x-model="filters.period" @change="onPeriodChange()">
                    <option value="">- Pilih Bulan -</option>
                    <template x-for="m in availableMonths()" :key="m">
                        <option :value="m" x-text="monthName(m)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Unit Kebun</label>
                <select class="filter-select" x-model="filters.unit" @change="onUnitChange()">
                    <option value="ALL">Semua Unit</option>
                    <template x-for="unit in units" :key="unit.code">
                        <option :value="unit.code" x-text="`${unit.code} - ${unit.name}`"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <!-- Kontrol laporan diteleport ke top header: dropdown view (Rekap/Rekap-2) + dropdown aksi -->
    <template x-teleport="#lm-header-controls">
        <div class="lm-hc" x-show="reportData" x-cloak>
            <select class="lm-hc-select" x-model="activeView" @change="switchView()" title="Pilih tampilan">
                <option value="rekap">Rekap</option>
                <option value="rekap2">Rekap-2</option>
            </select>
            <div class="lm-menu" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="lm-hc-btn" @click="open = !open" :aria-expanded="open">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                    Aksi
                </button>
                <div class="lm-menu-pop" x-show="open" x-cloak @click="open = false">
                    <button type="button" class="lm-menu-item" @click="loadReport()">Segarkan</button>
                    <button type="button" class="lm-menu-item" @click="toggleFocus()">Layar Penuh</button>
                    <div class="lm-menu-sep"></div>
                    <button type="button" class="lm-menu-item" @click="exportExcel()">Excel</button>
                    <button type="button" class="lm-menu-item" @click="exportCSV()">CSV</button>
                    <button type="button" class="lm-menu-item" @click="exportPDF()">PDF</button>
                    <button type="button" class="lm-menu-item" @click="print()">Cetak</button>
                </div>
            </div>
        </div>
    </template>

    <div class="report-card" x-show="reportData">
        <div class="tab-content active">
            <div x-show="loading" style="padding: 2rem; text-align: center; color: #666;">Loading...</div>
            <div x-show="!loading && investasiData" id="table-investasi" class="lm-report-table"></div>
        </div>

        <div class="lm-report-footer" x-show="investasiTable">
            <span x-text="footerText()"></span>
            <span>Nilai dalam Rupiah · Report final · terkunci</span>
        </div>
    </div>

    <div x-show="errorMessage" class="lm-error-panel" x-text="errorMessage"></div>

    <div x-show="!reportData" style="background: white; padding: 4rem; text-align: center; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">LM</div>
        <h3 style="color: #666; font-weight: 500;">Silakan pilih filter untuk melihat laporan</h3>
        <p style="color: #999; margin-top: 0.5rem;">Pilih Tahun, Bulan, dan Unit Kebun</p>
    </div>
</div>

@push('scripts')
<script>
function kebunInvestasiApp() {
    return {
        filters: {
            komoditi: 'KS',
            year: '',
            period: '',
            batch: '',
            unit: 'ALL'
        },
        monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        batches: [],
        units: [],
        selectedBatch: null,
        activeView: 'rekap',
        reportData: null,
        investasiData: null,
        loading: false,
        investasiTable: null,
        errorMessage: null,

        async init() {
            await this.loadBatches();
            this.$watch('filters.unit', () => this.emitTopbarUnit());
            this.$watch('reportData', (data) => this.emitTopbarKpi(data));

            // Saat mode fokus berubah (toggle/Esc/tombol keluar), render ulang tabel aktif
            // agar memakai lebar compact + tinggi penuh layar dan header tetap muncul.
            window.addEventListener('lm-focus-changed', () => setTimeout(() => this.rerenderActive(), 60));
            window.addEventListener('resize', () => {
                if (document.body.classList.contains('lm-focus')) {
                    this.rerenderActive();
                }
            });
        },

        toggleFocus() {
            const on = !document.body.classList.contains('lm-focus');
            document.body.classList.toggle('lm-focus', on);
            window.dispatchEvent(new Event('lm-focus-changed'));
        },

        rerenderActive() {
            if (this.investasiData) {
                this.investasiTable = window.LmReportTables.renderInvestasi(document.getElementById('table-investasi'), this.investasiData);
            }
        },

        emitTopbarUnit() {
            let label = '';
            if (this.filters.unit === 'ALL') {
                label = 'Semua Unit';
            } else {
                const unit = this.units.find((item) => String(item.code) === String(this.filters.unit));
                label = unit ? `${unit.code} - ${unit.name}` : '';
            }
            window.dispatchEvent(new CustomEvent('lm-topbar-unit', {
                detail: { label },
            }));
        },

        emitTopbarKpi(data) {
            window.dispatchEvent(new CustomEvent('lm-topbar-kpi', {
                detail: { kpi: data?.meta?.kpi_hari ?? null },
            }));
        },

        async loadBatches() {
            try {
                const response = await fetch('/api/batches');
                const data = await response.json();
                if (data.success) {
                    this.batches = data.data;
                }
            } catch (error) {
                console.error('Error loading batches:', error);
            }
        },

        monthName(m) {
            return this.monthNames[Number(m) - 1] ?? m;
        },

        availableYears() {
            return [...new Set(this.batches.map(b => b.year))].sort((a, b) => b - a);
        },

        availableMonths() {
            const list = this.filters.year
                ? this.batches.filter(b => String(b.year) === String(this.filters.year))
                : this.batches;
            return [...new Set(list.map(b => Number(b.period)))].sort((a, b) => a - b);
        },

        async onYearChange() {
            // Pertahankan periode bila masih valid di tahun yang baru.
            const prevPeriod = this.filters.period;
            this.filters.batch = '';
            this.selectedBatch = null;
            this.resetReport();
            this.filters.period = (prevPeriod && this.availableMonths().includes(Number(prevPeriod)))
                ? prevPeriod
                : '';
            if (this.filters.year && this.filters.period) {
                this.resolveBatch();
            }
            await this.loadUnits();
            this.reloadIfReady();
        },

        // Batch ditentukan otomatis dari Tahun + Bulan (unik per year+month),
        // jadi tidak perlu dropdown batch terpisah.
        resolveBatch() {
            const batch = this.batches.find(b =>
                String(b.year) === String(this.filters.year) &&
                String(b.period) === String(this.filters.period)
            );
            this.selectedBatch = batch ?? null;
            this.filters.batch = batch ? batch.id : '';
            return !!batch;
        },

        async loadUnits() {
            try {
                const params = new URLSearchParams({
                    type: 'KEBUN',
                    komoditi: this.filters.komoditi,
                });

                if (this.filters.batch) {
                    params.set('batch', this.filters.batch);
                }

                const response = await fetch(`/api/units?${params.toString()}`);
                const data = await response.json();
                if (data.success) {
                    this.units = data.data;
                }
            } catch (error) {
                console.error('Error loading units:', error);
            }
        },

        async onPeriodChange() {
            this.resetReport();
            if (this.filters.year && this.filters.period && !this.resolveBatch()) {
                this.errorMessage = 'Belum ada data untuk periode tersebut.';
                return;
            }
            await this.loadUnits();
            this.reloadIfReady();
        },

        onUnitChange() {
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        // Pertahankan pilihan unit saat filter lain berubah. Unit hanya dibuang bila
        // benar-benar tak tersedia di daftar baru. Bila masih valid, laporan dimuat ulang.
        reloadIfReady() {
            if (this.filters.unit && this.filters.unit !== 'ALL'
                && !this.units.some(u => String(u.code) === String(this.filters.unit))) {
                this.filters.unit = 'ALL';
            }
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        switchView() {
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        canLoadReport() {
            // unit 'ALL' (Semua Unit / konsolidasi) diizinkan — backend menjumlahkan semua kebun.
            return this.filters.komoditi && this.filters.batch && this.filters.unit;
        },

        resetReport() {
            this.reportData = null;
            this.investasiData = null;
            this.errorMessage = null;
        },

        async loadReport() {
            if (!this.canLoadReport()) {
                (window.lmToast || window.alert)('Silakan lengkapi filter terlebih dahulu', 'err');
                return;
            }

            this.loading = true;
            this.errorMessage = null;
            try {
                const url = `/report-data/lm-investasi?view=${this.activeView}&batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`;
                const data = await this.fetchReport(url);
                this.reportData = data;
                this.investasiData = data;
                this.$nextTick(() => {
                    this.investasiTable = window.LmReportTables.renderInvestasi(document.getElementById('table-investasi'), data);
                });
            } catch (error) {
                this.reportData = null;
                this.investasiData = null;
                this.errorMessage = error.message;
            } finally {
                this.loading = false;
            }
        },

        async fetchReport(url) {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-LM-Report-User': document.querySelector('meta[name="lm-report-user"]')?.content ?? '',
                    'X-LM-Report-Token': document.querySelector('meta[name="lm-report-token"]')?.content ?? ''
                },
                credentials: 'include'
            });

            if (response.redirected && new URL(response.url).pathname === '/login') {
                throw new Error('Sesi login tidak terbaca saat memuat laporan. Refresh halaman lalu login ulang jika masih terjadi.');
            }

            if (!response.ok) {
                if (response.status === 401) {
                    throw new Error('Sesi login tidak terbaca saat memuat laporan. Refresh halaman lalu login ulang jika masih terjadi.');
                }
                const errorData = await response.json().catch(() => null);
                if (response.status === 403) {
                    throw new Error(errorData?.message || 'Akses ditolak. Viewer hanya dapat melihat batch final atau locked.');
                }
                throw new Error(errorData?.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Server mengembalikan halaman, bukan data laporan. Pastikan URL aktif adalah /kebun/investasi.');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Gagal memuat data laporan');
            }

            return data;
        },

        exportExcel() {
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportExcel(table, `INVESTASI-${this.activeView}-${this.filters.unit}-${this.filters.period}.xls`);
            }
        },

        exportCSV() {
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportCsv(table, `INVESTASI-${this.activeView}-${this.filters.unit}-${this.filters.period}.csv`);
            }
        },

        exportPDF() {
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportPdf(table, `INVESTASI ${this.activeView} ${this.filters.unit}`);
            }
        },

        print() {
            window.print();
        },

        activeTable() {
            return this.investasiTable;
        },

        footerText() {
            const table = this.activeTable();
            const rows = table ? table.getData('active').length : 0;
            const columns = table ? table.getColumns(true).length : 0;
            return `Menampilkan ${rows} baris · ${columns} kolom`;
        }
    }
}
</script>
@endpush
@endsection
