@extends('layouts.app')

@section('title', 'Laporan Pabrik')

@section('content')
<div x-data="pabrikApp()" x-init="init()">
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Komoditas</label>
                <select class="filter-select" x-model="filters.komoditi" @change="onKomoditiChange()">
                    <option value="">- Pilih Komoditas -</option>
                    <option value="KS">Kelapa Sawit (PKS)</option>
                    <!-- Karet/PKR untuk fase berikutnya -->
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
                <label class="filter-label">Unit Pabrik</label>
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
            <h2 class="report-title" x-text="reportData ? `Laporan Pabrik - ${reportData.meta?.unit?.name}` : 'Laporan Pabrik'"></h2>
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

        <!-- Tabs (hanya 1 tab untuk pabrik) -->
        <div class="tabs">
            <div class="tab active">
                LM 16
            </div>
        </div>

        <!-- Tab Content: LM16 -->
        <div class="tab-content active">
            <div x-show="loadingLm16" style="padding: 2rem; text-align: center; color: #666;">
                Loading LM16...
            </div>
            <div x-show="!loadingLm16 && lm16Data" id="table-lm16" style="overflow-x: auto;">
                <!-- Tabel LM16 akan di-render di sini via Tabulator (prompt_08) -->
                <p style="padding: 2rem; text-align: center; color: #999;">
                    Tabel LM16 akan diimplementasikan di Prompt_08
                </p>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div x-show="!reportData" style="background: white; padding: 4rem; text-align: center; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🏭</div>
        <h3 style="color: #666; font-weight: 500;">Silakan pilih filter untuk melihat laporan</h3>
        <p style="color: #999; margin-top: 0.5rem;">Pilih Komoditas, Batch, dan Unit Pabrik</p>
    </div>
</div>

@push('scripts')
<script>
function pabrikApp() {
    return {
        filters: {
            komoditi: 'KS', // Default ke Sawit
            period: '',
            batch: '',
            unit: ''
        },
        batches: [],
        units: [],
        selectedBatch: null,
        searchText: '',
        reportData: null,
        lm16Data: null,
        loadingLm16: false,

        async init() {
            await this.loadBatches();
            await this.loadUnits(); // Load units untuk Sawit (default)
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
                const response = await fetch(`/api/units?type=PABRIK&komoditi=${this.filters.komoditi}`);
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

            await this.loadLm16();
        },

        async loadLm16() {
            this.loadingLm16 = true;
            try {
                const url = `/api/report/lm16?batch=${this.filters.batch}&unit=${this.filters.unit}`;
                const response = await fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    if (response.status === 401 || response.status === 403) {
                        alert('Sesi Anda telah berakhir. Silakan login kembali.');
                        window.location.href = '/login';
                        return;
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();

                if (data.success) {
                    this.reportData = data;
                    this.lm16Data = data;
                    console.log('LM16 loaded:', data.rows.length, 'rows');
                } else {
                    alert(data.message || 'Gagal memuat data LM16');
                }
            } catch (error) {
                console.error('Error loading LM16:', error);
                alert('Terjadi kesalahan saat memuat data: ' + error.message);
            } finally {
                this.loadingLm16 = false;
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
