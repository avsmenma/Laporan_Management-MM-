@extends('layouts.app')

@section('title', 'Laporan Pabrik')
@section('unit-label', 'Unit Pabrik: ')

@section('content')
<div x-data="pabrikApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Komoditas</label>
                <select class="filter-select" x-model="filters.komoditi" @change="onKomoditiChange()">
                    <option value="">- Pilih Komoditas -</option>
                    <option value="KS">Kelapa Sawit (PKS)</option>
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
                <label class="filter-label">Unit Pabrik</label>
                <select class="filter-select" x-model="filters.unit" @change="onUnitChange()">
                    <option value="">- Pilih Unit -</option>
                    <option value="ALL">Semua Unit</option>
                    <template x-for="unit in units" :key="unit.code">
                        <option :value="unit.code" x-text="`${unit.code} - ${unit.name}`"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <!-- Kontrol laporan diteleport ke top header: label LM16 + dropdown aksi -->
    <template x-teleport="#lm-header-controls">
        <div class="lm-hc" x-show="reportData" x-cloak>
            <span class="lm-hc-static">LM 16</span>
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
            <div x-show="loadingLm16" style="padding: 2rem; text-align: center; color: #666;">Loading LM16...</div>
            <div x-show="!loadingLm16 && lm16Data" id="table-lm16" class="lm-report-table" @lm-cell-click="handleCellClick($event)"></div>
        </div>

        <div class="lm-report-footer" x-show="lm16Table">
            <span x-text="footerText()"></span>
            <span>Nilai dalam Rupiah · Report final · terkunci</span>
        </div>
    </div>

    <div x-show="errorMessage" class="lm-error-panel" x-text="errorMessage"></div>

    <div x-show="!reportData" style="background: white; padding: 4rem; text-align: center; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">LM</div>
        <h3 style="color: #666; font-weight: 500;">Silakan pilih filter untuk melihat laporan</h3>
        <p style="color: #999; margin-top: 0.5rem;">Pilih Tahun, Bulan, dan Unit Pabrik</p>
    </div>
</div>

@push('scripts')
<script>
function pabrikApp() {
    return {
        filters: {
            komoditi: 'KS',
            year: '',
            period: '',
            batch: '',
            unit: ''
        },
        monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        batches: [],
        units: [],
        selectedBatch: null,
        searchText: '',
        reportData: null,
        lm16Data: null,
        loadingLm16: false,
        lm16Table: null,
        drilldownPreview: null,
        errorMessage: null,

        async init() {
            await this.loadBatches();
            await this.loadUnits();
            this.$watch('searchText', (value) => {
                window.LmReportTables.applySearch(this.lm16Table, value);
            });
            this.$watch('filters.unit', () => this.emitTopbarUnit());
            this.$watch('reportData', (data) => this.emitTopbarKpi(data));

            window.addEventListener('lm-focus-changed', () => setTimeout(() => this.rerenderActive(), 60));
            window.addEventListener('resize', () => {
                if (document.body.classList.contains('lm-focus')) {
                    this.rerenderActive();
                }
            });
        },

        rerenderActive() {
            if (this.lm16Data) {
                this.lm16Table = window.LmReportTables.renderTable(document.getElementById('table-lm16'), 'LM16', this.lm16Data);
                window.LmReportTables.applySearch(this.lm16Table, this.searchText);
            }
        },

        emitTopbarUnit() {
            const unit = this.units.find((item) => String(item.code) === String(this.filters.unit));
            window.dispatchEvent(new CustomEvent('lm-topbar-unit', {
                detail: { label: unit ? `${unit.code} - ${unit.name}` : '' },
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

        onYearChange() {
            this.filters.period = '';
            this.filters.batch = '';
            this.filters.unit = '';
            this.selectedBatch = null;
            this.units = [];
            this.resetReport();
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
            if (!this.filters.komoditi) {
                this.units = [];
                return;
            }

            try {
                const params = new URLSearchParams({
                    type: 'PABRIK',
                    komoditi: this.filters.komoditi,
                });

                if (this.filters.batch) {
                    params.set('batch', this.filters.batch);
                    params.set('report_type', 'LM16');
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

        onKomoditiChange() {
            this.filters.unit = '';
            this.units = [];
            this.resetReport();
            this.loadUnits();
        },

        onPeriodChange() {
            this.filters.unit = '';
            this.units = [];
            this.resetReport();
            if (this.filters.year && this.filters.period && !this.resolveBatch()) {
                this.errorMessage = 'Belum ada data untuk periode tersebut.';
                return;
            }
            this.loadUnits();
        },

        onUnitChange() {
            if (this.filters.unit === 'ALL') {
                this.resetReport();
                this.errorMessage = 'Laporan konsolidasi "Semua Unit" belum tersedia. Silakan pilih satu unit pabrik.';
                return;
            }
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        canLoadReport() {
            return this.filters.komoditi && this.filters.batch && this.filters.unit && this.filters.unit !== 'ALL';
        },

        resetReport() {
            this.reportData = null;
            this.lm16Data = null;
            this.drilldownPreview = null;
            this.errorMessage = null;
        },

        async loadReport() {
            if (!this.canLoadReport()) {
                (window.lmToast || window.alert)('Silakan lengkapi filter terlebih dahulu', 'err');
                return;
            }

            await this.loadLm16();
        },

        async loadLm16() {
            this.loadingLm16 = true;
            this.errorMessage = null;
            try {
                const data = await this.fetchReport(`/report-data/lm16?batch=${this.filters.batch}&unit=${this.filters.unit}`);
                this.reportData = data;
                this.lm16Data = data;
                this.$nextTick(() => {
                    this.lm16Table = window.LmReportTables.renderTable(document.getElementById('table-lm16'), 'LM16', data);
                    window.LmReportTables.applySearch(this.lm16Table, this.searchText);
                });
            } catch (error) {
                this.reportData = null;
                this.lm16Data = null;
                this.errorMessage = error.message;
            } finally {
                this.loadingLm16 = false;
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
                throw new Error('Server mengembalikan halaman, bukan data laporan. Pastikan URL aktif adalah /kebun atau /pabrik.');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Gagal memuat data laporan');
            }

            return data;
        },

        exportExcel() {
            if (this.lm16Table) {
                window.LmReportTables.exportExcel(this.lm16Table, `LM16-${this.filters.unit}-${this.filters.period}.xls`);
            }
        },

        exportCSV() {
            if (this.lm16Table) {
                window.LmReportTables.exportCsv(this.lm16Table, `LM16-${this.filters.unit}-${this.filters.period}.csv`);
            }
        },

        exportPDF() {
            if (this.lm16Table) {
                window.LmReportTables.exportPdf(this.lm16Table, `LM16 ${this.filters.unit}`);
            }
        },

        print() {
            window.print();
        },

        toggleFocus() {
            const on = !document.body.classList.contains('lm-focus');
            document.body.classList.toggle('lm-focus', on);
            window.dispatchEvent(new Event('lm-focus-changed'));
        },

        footerText() {
            const rows = this.lm16Table ? this.lm16Table.getData('active').length : 0;
            const columns = this.lm16Table ? this.lm16Table.getColumns(true).length : 0;
            return `Menampilkan ${rows} baris · ${columns} kolom`;
        },

        handleCellClick(event) {
            this.drilldownPreview = {
                report_type: 'LM16',
                unit: this.filters.unit,
                batch: this.filters.batch,
                komoditi: this.filters.komoditi,
                kode_baris: event.detail.drilldown.kode_baris,
                column_key: event.detail.drilldown.column_key,
            };
        }
    }
}
</script>
@endpush
@endsection
