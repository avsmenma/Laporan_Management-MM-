@extends('layouts.app')

@section('title', 'Laporan Kebun')

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
                <label class="filter-label">Periode (Bulan)</label>
                <select class="filter-select" x-model="filters.period" @change="onPeriodChange()">
                    <option value="">- Pilih Periode -</option>
                    <template x-for="month in 12" :key="month">
                        <option :value="month" x-text="month"></option>
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
                <div x-show="filters.batch" class="filter-meta" style="font-size: 0.75rem; color: #666; margin-top: 0.25rem;">
                    <span x-text="selectedBatch ? `Tahun: ${selectedBatch.year} | Status: ${selectedBatch.status}` : ''"></span>
                </div>
            </div>

            <div class="filter-group">
                <label class="filter-label">Unit Kebun</label>
                <select class="filter-select" x-model="filters.unit" @change="onUnitChange()">
                    <option value="">- Pilih Unit -</option>
                    <template x-for="unit in units" :key="unit.code">
                        <option :value="unit.code" x-text="`${unit.code} - ${unit.name}`"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div class="report-card" x-show="reportData">
        <div class="report-header">
            <h2 class="report-title" x-text="reportData ? `Laporan Kebun - ${reportData.meta?.unit?.name}` : 'Laporan Kebun'"></h2>
            <div class="report-meta">
                <span x-text="reportData ? `Komoditas: ${reportData.meta?.unit?.komoditi} | Periode: ${reportData.meta?.batch?.period}/${reportData.meta?.batch?.year}` : ''"></span>
            </div>
        </div>

        <div class="kpi-strip" x-show="reportData">
            <div class="kpi-item">
                <div class="kpi-label">Jumlah Hari Sebulan</div>
                <div class="kpi-value">
                    <span x-text="reportData?.meta?.kpi_hari?.jumlah_hari || 0"></span>
                    <span class="kpi-unit">hari</span>
                </div>
            </div>
            <div class="kpi-item">
                <div class="kpi-label">Hari Dijalani</div>
                <div class="kpi-value">
                    <span x-text="reportData?.meta?.kpi_hari?.hari_dijalani || 0"></span>
                    <span class="kpi-unit">hari</span>
                </div>
            </div>
            <div class="kpi-item">
                <div class="kpi-label">Sisa Hari</div>
                <div class="kpi-value">
                    <span x-text="reportData?.meta?.kpi_hari?.sisa_hari || 0"></span>
                    <span class="kpi-unit">hari</span>
                </div>
            </div>
        </div>

        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" class="search-input" placeholder="Cari baris..." x-model="searchText">
            </div>
            <div class="toolbar-right">
                <button class="btn" @click="exportExcel()">Excel</button>
                <button class="btn" @click="exportCSV()">CSV</button>
                <button class="btn" @click="exportPDF()">PDF</button>
                <button class="btn" @click="print()">Cetak</button>
                <button class="btn btn-primary" @click="loadReport()">Refresh</button>
            </div>
        </div>

        <div class="lm-drill-preview" x-show="drilldownPreview">
            <strong>Dasar nilai:</strong>
            <span x-text="drilldownPreview ? `${drilldownPreview.report_type} ${drilldownPreview.kode_baris} - ${drilldownPreview.column_key}` : ''"></span>
            <span> akan dibuka penuh pada prompt_09.</span>
        </div>

        <div class="tabs">
            <div class="tab" :class="{ 'active': activeTab === 'lm14' }" @click="switchTab('lm14')">
                LM 14
            </div>
            <div class="tab" :class="{ 'active': activeTab === 'lm13' }" @click="switchTab('lm13')">
                LM 13
            </div>
        </div>

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
            period: '',
            batch: '',
            unit: ''
        },
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

        filteredBatches() {
            if (!this.filters.period) {
                return this.batches;
            }

            return this.batches.filter(batch => String(batch.period) === String(this.filters.period));
        },

        async loadUnits() {
            if (!this.filters.komoditi) {
                this.units = [];
                return;
            }

            try {
                const response = await fetch(`/api/units?type=KEBUN&komoditi=${this.filters.komoditi}`);
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
                this.selectedBatch = null;
                this.resetReport();
            }
        },

        onBatchChange() {
            this.selectedBatch = this.batches.find(b => b.id == this.filters.batch);
            if (this.selectedBatch) {
                this.filters.period = this.selectedBatch.period;
            }
            this.resetReport();
        },

        onUnitChange() {
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        canLoadReport() {
            return this.filters.komoditi && this.filters.batch && this.filters.unit;
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
                const data = await this.fetchReport(`/api/report/lm14?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`);
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
                const data = await this.fetchReport(`/api/report/lm13?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`);
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
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (response.redirected && new URL(response.url).pathname === '/login') {
                throw new Error('Sesi login tidak terbaca saat memuat laporan. Refresh halaman lalu login ulang jika masih terjadi.');
            }

            if (!response.ok) {
                if (response.status === 401 || response.status === 403) {
                    throw new Error('Sesi login tidak terbaca saat memuat laporan. Refresh halaman lalu login ulang jika masih terjadi.');
                }
                const errorData = await response.json().catch(() => null);
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
