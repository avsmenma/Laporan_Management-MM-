@extends('layouts.app')

@section('title', 'Laporan Kebun')

@section('content')
<div x-data="kebunApp()" x-init="init()">
    <!-- Filter Bar -->
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
                    <template x-for="batch in batches" :key="batch.id">
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

    <!-- Report Card -->
    <div class="report-card" x-show="reportData">
        <!-- Header -->
        <div class="report-header">
            <h2 class="report-title" x-text="reportData ? `Laporan Kebun - ${reportData.meta?.unit?.name}` : 'Laporan Kebun'"></h2>
            <div class="report-meta">
                <span x-text="reportData ? `Komoditas: ${reportData.meta?.unit?.komoditi} | Periode: ${reportData.meta?.batch?.period}/${reportData.meta?.batch?.year}` : ''"></span>
            </div>
        </div>

        <!-- KPI Strip -->
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

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" class="search-input" placeholder="Cari baris..." x-model="searchText">
            </div>
            <div class="toolbar-right">
                <button class="btn" @click="exportExcel()">📊 Excel</button>
                <button class="btn" @click="exportCSV()">📄 CSV</button>
                <button class="btn" @click="exportPDF()">📕 PDF</button>
                <button class="btn" @click="print()">🖨️ Cetak</button>
                <button class="btn btn-primary" @click="loadReport()">🔄 Refresh</button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab" :class="{ 'active': activeTab === 'lm14' }" @click="activeTab = 'lm14'">
                LM 14
            </div>
            <div class="tab" :class="{ 'active': activeTab === 'lm13' }" @click="activeTab = 'lm13'">
                LM 13
            </div>
        </div>

        <!-- Tab Content: LM14 -->
        <div class="tab-content" :class="{ 'active': activeTab === 'lm14' }">
            <div x-show="loadingLm14" style="padding: 2rem; text-align: center; color: #666;">
                Loading LM14...
            </div>
            <div x-show="!loadingLm14 && lm14Data" id="table-lm14" style="overflow-x: auto;">
                <!-- Tabel LM14 akan di-render di sini via Tabulator (prompt_08) -->
                <p style="padding: 2rem; text-align: center; color: #999;">
                    Tabel LM14 akan diimplementasikan di Prompt_08
                </p>
            </div>
        </div>

        <!-- Tab Content: LM13 -->
        <div class="tab-content" :class="{ 'active': activeTab === 'lm13' }">
            <div x-show="loadingLm13" style="padding: 2rem; text-align: center; color: #666;">
                Loading LM13...
            </div>
            <div x-show="!loadingLm13 && lm13Data" id="table-lm13" style="overflow-x: auto;">
                <!-- Tabel LM13 akan di-render di sini via Tabulator (prompt_08) -->
                <p style="padding: 2rem; text-align: center; color: #999;">
                    Tabel LM13 akan diimplementasikan di Prompt_08
                </p>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div x-show="!reportData" style="background: white; padding: 4rem; text-align: center; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">📊</div>
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

        async init() {
            await this.loadBatches();
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
            this.reportData = null;
            this.loadUnits();
        },

        onPeriodChange() {
            // Filter batches by period if needed
        },

        onBatchChange() {
            this.selectedBatch = this.batches.find(b => b.id == this.filters.batch);
            if (this.selectedBatch) {
                this.filters.period = this.selectedBatch.period;
            }
            this.reportData = null;
        },

        onUnitChange() {
            if (this.canLoadReport()) {
                this.loadReport();
            }
        },

        canLoadReport() {
            return this.filters.komoditi && this.filters.batch && this.filters.unit;
        },

        async loadReport() {
            if (!this.canLoadReport()) {
                alert('Silakan lengkapi filter terlebih dahulu');
                return;
            }

            if (this.activeTab === 'lm14') {
                await this.loadLm14();
            } else if (this.activeTab === 'lm13') {
                await this.loadLm13();
            }
        },

        async loadLm14() {
            this.loadingLm14 = true;
            try {
                const url = `/api/report/lm14?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`;
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    this.reportData = data;
                    this.lm14Data = data;
                    console.log('LM14 loaded:', data.rows.length, 'rows');
                } else {
                    alert(data.message || 'Gagal memuat data LM14');
                }
            } catch (error) {
                console.error('Error loading LM14:', error);
                alert('Terjadi kesalahan saat memuat data');
            } finally {
                this.loadingLm14 = false;
            }
        },

        async loadLm13() {
            this.loadingLm13 = true;
            try {
                const url = `/api/report/lm13?batch=${this.filters.batch}&unit=${this.filters.unit}&komoditi=${this.filters.komoditi}`;
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    this.reportData = data;
                    this.lm13Data = data;
                    console.log('LM13 loaded:', data.rows.length, 'rows');
                } else {
                    alert(data.message || 'Gagal memuat data LM13');
                }
            } catch (error) {
                console.error('Error loading LM13:', error);
                alert('Terjadi kesalahan saat memuat data');
            } finally {
                this.loadingLm13 = false;
            }
        },

        exportExcel() {
            alert('Export Excel akan diimplementasikan di prompt_08');
        },

        exportCSV() {
            alert('Export CSV akan diimplementasikan di prompt_08');
        },

        exportPDF() {
            alert('Export PDF akan diimplementasikan di prompt_08');
        },

        print() {
            window.print();
        }
    }
}
</script>
@endpush
@endsection
