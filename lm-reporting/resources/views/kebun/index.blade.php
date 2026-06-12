@extends('layouts.app')

@section('title', 'Laporan Kebun')
@section('unit-label', 'Unit Kebun: ')

@section('content')
<div x-data="kebunApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Komoditas</label>
                <select class="filter-select" x-model="filters.komoditi" @change="onKomoditiChange()">
                    <option value="">- Pilih Komoditas -</option>
                    <option value="KS">Kelapa Sawit</option>
                    <option value="KR">Karet</option>
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
                <label class="filter-label">Batch</label>
                <select class="filter-select" x-model="filters.batch" @change="onBatchChange()">
                    <option value="">- Pilih Batch -</option>
                    <template x-for="batch in filteredBatches()" :key="batch.id">
                        <option :value="batch.id" x-text="batch.label"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Unit Kebun</label>
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

    <!-- Kontrol laporan diteleport ke top header: dropdown tab (LM14/LM13) + dropdown aksi -->
    <template x-teleport="#lm-header-controls">
        <div class="lm-hc" x-show="reportData" x-cloak>
            <select class="lm-hc-select" x-model="activeTab" @change="switchTab(activeTab)" title="Pilih laporan">
                <option value="lm14">LM 14</option>
                <option value="lm13">LM 13</option>
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
        <div class="tab-content" :class="{ 'active': activeTab === 'lm14' }">
            <div x-show="loadingLm14" style="padding: 2rem; text-align: center; color: #666;">Loading LM14...</div>
            <div x-show="!loadingLm14 && lm14Data" id="table-lm14" class="lm-report-table" @lm-cell-click="handleCellClick($event)"></div>
        </div>

        <div class="tab-content" :class="{ 'active': activeTab === 'lm13' }">
            <div x-show="loadingLm13" style="padding: 2rem; text-align: center; color: #666;">Loading LM13...</div>
            <div x-show="!loadingLm13 && lm13Data" id="table-lm13" class="lm-report-table" @lm-cell-click="handleCellClick($event)"></div>
        </div>

        <div class="lm-report-footer" x-show="activeTable()">
            <span x-text="footerText()"></span>
            <span>Nilai dalam Rupiah · Report final · terkunci</span>
        </div>
    </div>

    <div x-show="errorMessage" class="lm-error-panel" x-text="errorMessage"></div>

    <div x-show="!reportData" style="background: white; padding: 4rem; text-align: center; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">LM</div>
        <h3 style="color: #666; font-weight: 500;">Silakan pilih filter untuk melihat laporan</h3>
        <p style="color: #999; margin-top: 0.5rem;">Pilih Komoditas, Batch, dan Unit Kebun</p>
    </div>
</div>

@push('scripts')
<script>
function kebunApp() {
    return {
        filters: {
            komoditi: '',
            year: '',
            period: '',
            batch: '',
            unit: ''
        },
        monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        batches: [],
        units: [],
        selectedBatch: null,
        activeTab: 'lm14',
        searchText: '',
        reportData: null,
        lm14Data: null,
        lm13Data: null,
        loadingLm14: false,
        loadingLm13: false,
        lm14Table: null,
        lm13Table: null,
        drilldownPreview: null,
        errorMessage: null,

        async init() {
            await this.loadBatches();
            this.$watch('searchText', (value) => {
                window.LmReportTables.applySearch(this.activeTable(), value);
            });
            this.$watch('filters.unit', () => this.emitTopbarUnit());
            this.$watch('reportData', (data) => this.emitTopbarKpi(data));
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
            this.filters.batch = '';
            this.filters.unit = '';
            this.selectedBatch = null;
            this.units = [];
            this.resetReport();
        },

        filteredBatches() {
            return this.batches.filter(batch =>
                (!this.filters.year || String(batch.year) === String(this.filters.year)) &&
                (!this.filters.period || String(batch.period) === String(this.filters.period))
            );
        },

        async loadUnits() {
            if (!this.filters.komoditi) {
                this.units = [];
                return;
            }

            try {
                const params = new URLSearchParams({
                    type: 'KEBUN',
                    komoditi: this.filters.komoditi,
                });

                if (this.filters.batch) {
                    params.set('batch', this.filters.batch);
                    params.set('report_type', this.activeReportType());
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
            if (this.selectedBatch && String(this.selectedBatch.period) !== String(this.filters.period)) {
                this.filters.batch = '';
                this.filters.unit = '';
                this.selectedBatch = null;
                this.units = [];
                this.resetReport();
            }
        },

        onBatchChange() {
            this.selectedBatch = this.batches.find(b => b.id == this.filters.batch);
            if (this.selectedBatch) {
                this.filters.period = this.selectedBatch.period;
                this.filters.year = this.selectedBatch.year;
            }
            this.filters.unit = '';
            this.resetReport();
            this.loadUnits();
        },

        onUnitChange() {
            if (this.filters.unit === 'ALL') {
                this.resetReport();
                this.errorMessage = 'Laporan konsolidasi "Semua Unit" belum tersedia. Silakan pilih satu unit kebun.';
                return;
            }
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            // Pertahankan unit yang sudah dipilih, segarkan daftar unit di latar,
            // lalu langsung muat laporan tab ini tanpa memilih ulang kebun.
            this.loadUnits();
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        canLoadReport() {
            return this.filters.komoditi && this.filters.batch && this.filters.unit && this.filters.unit !== 'ALL';
        },

        resetReport() {
            this.reportData = null;
            this.lm14Data = null;
            this.lm13Data = null;
            this.drilldownPreview = null;
            this.errorMessage = null;
        },

        async loadReport() {
            if (!this.canLoadReport()) {
                alert('Silakan lengkapi filter terlebih dahulu');
                return;
            }

            if (this.activeTab === 'lm14') {
                await this.loadLm14();
            } else {
                await this.loadLm13();
            }
        },

        async loadLm14() {
            this.loadingLm14 = true;
            this.errorMessage = null;
            try {
                const data = await this.fetchReport(`/report-data/lm14?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`);
                this.reportData = data;
                this.lm14Data = data;
                this.$nextTick(() => {
                    this.lm14Table = window.LmReportTables.renderTable(document.getElementById('table-lm14'), 'LM14', data);
                    window.LmReportTables.applySearch(this.lm14Table, this.searchText);
                });
            } catch (error) {
                this.reportData = null;
                this.lm14Data = null;
                this.errorMessage = error.message;
            } finally {
                this.loadingLm14 = false;
            }
        },

        async loadLm13() {
            this.loadingLm13 = true;
            this.errorMessage = null;
            try {
                const data = await this.fetchReport(`/report-data/lm13?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`);
                this.reportData = data;
                this.lm13Data = data;
                this.$nextTick(() => {
                    this.lm13Table = window.LmReportTables.renderTable(document.getElementById('table-lm13'), 'LM13', data);
                    window.LmReportTables.applySearch(this.lm13Table, this.searchText);
                });
            } catch (error) {
                this.reportData = null;
                this.lm13Data = null;
                this.errorMessage = error.message;
            } finally {
                this.loadingLm13 = false;
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
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportExcel(table, `${this.activeReportType()}-${this.filters.unit}-${this.filters.period}.xls`);
            }
        },

        exportCSV() {
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportCsv(table, `${this.activeReportType()}-${this.filters.unit}-${this.filters.period}.csv`);
            }
        },

        exportPDF() {
            const table = this.activeTable();
            if (table) {
                window.LmReportTables.exportPdf(table, `${this.activeReportType()} ${this.filters.unit}`);
            }
        },

        print() {
            window.print();
        },

        toggleFocus() {
            const on = !document.body.classList.contains('lm-focus');
            document.body.classList.toggle('lm-focus', on);
        },

        activeTable() {
            return this.activeTab === 'lm14' ? this.lm14Table : this.lm13Table;
        },

        activeReportType() {
            return this.activeTab === 'lm14' ? 'LM14' : 'LM13';
        },

        footerText() {
            const table = this.activeTable();
            const rows = table ? table.getData('active').length : 0;
            const columns = table ? table.getColumns(true).length : 0;
            return `Menampilkan ${rows} baris · ${columns} kolom`;
        },

        handleCellClick(event) {
            this.drilldownPreview = {
                report_type: this.activeReportType(),
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
