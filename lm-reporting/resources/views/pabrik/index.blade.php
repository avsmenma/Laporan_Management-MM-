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

    <!-- Popup rincian sumber (drill-down) saat angka tabel LM16 diklik -->
    <div class="lm-dd-overlay lm-dd-pks" x-show="drill.open" x-cloak
         @keydown.escape.window="drill.open && closeDrill()" @click.self="closeDrill()">
        <div class="lm-dd-modal">
            <div class="lm-dd-head">
                <button type="button" class="lm-dd-back" x-show="drill.view==='deep' && !drill.direct" @click="backToPivot()" title="Kembali ke rincian">&larr;</button>
                <div class="lm-dd-titles">
                    <div class="lm-dd-title" x-text="drill.title"></div>
                    <div class="lm-dd-bc" x-show="drill.view==='pivot'">
                        <span class="lm-dd-chip" x-text="drill.columnLabel"></span>
                        <span class="lm-dd-total">Rp <span x-text="fmtNum(drill.value)"></span></span>
                    </div>
                    <div class="lm-dd-bc" x-show="drill.view==='deep'">
                        <template x-if="!drill.direct">
                            <span style="display:flex;align-items:center;gap:10px">
                                <span class="lm-dd-chip" x-text="drill.deep.pb7"></span>
                                <span class="lm-dd-bc-sep">›</span>
                                <span class="lm-dd-chip" x-text="drill.deep.pb712"></span>
                            </span>
                        </template>
                        <template x-if="drill.direct">
                            <span class="lm-dd-chip" x-text="drill.columnLabel"></span>
                        </template>
                        <span class="lm-dd-total">Rp <span x-text="fmtNum(drill.deep.value)"></span></span>
                    </div>
                </div>
                <button type="button" class="lm-dd-close" @click="closeDrill()" aria-label="Tutup">&times;</button>
            </div>

            <div class="lm-dd-body">
                <!-- VIEW: pivot rincian sumber (level 1) — per Cost Center × Cost Element -->
                <div x-show="drill.view==='pivot'">
                    <div x-show="drill.loading" class="lm-dd-state">Memuat rincian sumber…</div>
                    <div x-show="!drill.loading && drill.error" class="lm-dd-state lm-dd-err" x-text="drill.error"></div>
                    <div x-show="!drill.loading && !drill.error && (!drill.pivot || !drill.pivot.row_count)" class="lm-dd-state"
                         x-text="drill.message || 'Tidak ada rincian sumber untuk sel ini.'"></div>

                    <div class="lm-dd-hint" x-show="!drill.loading && !drill.error && drill.pivot && drill.pivot.row_count">
                        Klik salah satu angka untuk melihat data sumber (posting biaya) per baris.
                    </div>
                    <div class="lm-dd-tablewrap" x-show="!drill.loading && !drill.error && drill.pivot && drill.pivot.row_count">
                        <template x-if="drill.pivot && drill.pivot.row_count">
                        <table class="lm-dd-table">
                            <thead>
                                <tr>
                                    <th class="lm-dd-l">SUB REKENING</th>
                                    <th class="lm-dd-l">Kode B</th>
                                    <template x-for="cat in (drill.pivot?.categories ?? [])" :key="cat">
                                        <th class="lm-dd-n" x-text="cat"></th>
                                    </template>
                                    <th class="lm-dd-n">Grand Total</th>
                                </tr>
                            </thead>
                            <template x-for="(group, gi) in (drill.pivot?.groups ?? [])" :key="gi">
                                <tbody>
                                    <template x-for="(r, ri) in group.rows" :key="ri">
                                        <tr>
                                            <td class="lm-dd-l lm-dd-pb7"><span class="lm-dd-dot" x-show="ri === 0"></span><span x-text="ri === 0 ? group.pb7 : ''"></span></td>
                                            <td class="lm-dd-l"><span class="lm-dd-code" x-text="r.pb712"></span></td>
                                            <template x-for="cat in drill.pivot.categories" :key="cat">
                                                <td class="lm-dd-n" :class="{ 'lm-dd-clickable': r.values[cat] }"
                                                    @click="openDeep(group.pb7, r.pb712, cat, r.values[cat])"
                                                    x-text="fmtNum(r.values[cat])"></td>
                                            </template>
                                            <td class="lm-dd-n lm-dd-rowtot lm-dd-clickable"
                                                @click="openDeep(group.pb7, r.pb712, null, r.total)"
                                                x-text="fmtNum(r.total)"></td>
                                        </tr>
                                    </template>
                                    <tr class="lm-dd-subrow">
                                        <td class="lm-dd-l" colspan="2" x-text="group.pb7 + ' Total'"></td>
                                        <template x-for="cat in drill.pivot.categories" :key="cat">
                                            <td class="lm-dd-n" x-text="fmtNum(group.subtotal[cat])"></td>
                                        </template>
                                        <td class="lm-dd-n" x-text="fmtNum(group.subtotal_total)"></td>
                                    </tr>
                                </tbody>
                            </template>
                        </table>
                        </template>
                    </div>
                </div>

                <!-- VIEW: rincian lebih dalam (level 2) -->
                <div x-show="drill.view==='deep'">
                    <div x-show="drill.deep.loading" class="lm-dd-state">Memuat rincian lebih dalam…</div>
                    <div x-show="!drill.deep.loading && drill.deep.error" class="lm-dd-state lm-dd-err" x-text="drill.deep.error"></div>
                    <div x-show="!drill.deep.loading && !drill.deep.error && (!drill.deep.data || !drill.deep.data.row_count)" class="lm-dd-state">
                        Tidak ada rincian lebih dalam untuk sel ini.
                    </div>
                    <div class="lm-dd-tablewrap lm-dd-drag"
                         x-show="!drill.deep.loading && !drill.deep.error && drill.deep.data && drill.deep.data.row_count"
                         x-ref="deepScroll"
                         @mousedown="deepDragStart($event)"
                         @mousemove.window="deepDragMove($event)"
                         @mouseup.window="deepDragEnd()"
                         x-html="drill.deep.html"></div>
                </div>
            </div>
        </div>
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
        drill: {
            open: false, view: 'pivot', direct: false, loading: false, error: null,
            title: '', columnLabel: '', value: 0, pivot: null, message: null,
            ctx: { kode: '', column: '' },
            deep: { loading: false, error: null, data: null, html: '', pb7: '', pb712: '', klasifikasi: '', value: 0 },
        },
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

        async handleCellClick(event) {
            const dd = event.detail.drilldown;
            this.drill = {
                open: true, view: 'pivot', direct: false, loading: true, error: null,
                title: String(event.detail.row?.uraian ?? '').trim() || dd.kode_baris,
                columnLabel: '', value: Number(event.detail.value ?? 0), pivot: null, message: null,
                ctx: { kode: dd.kode_baris, column: dd.column_key },
                deep: { loading: false, error: null, data: null, html: '', pb7: '', pb712: '', klasifikasi: '', value: 0 },
            };

            try {
                const params = new URLSearchParams({
                    type: 'LM16',
                    batch: this.filters.batch,
                    unit: this.filters.unit,
                    komoditi: this.filters.komoditi,
                    kode: dd.kode_baris,
                    column: dd.column_key,
                });
                const data = await this.fetchReport(`/report-data/drilldown?${params.toString()}`);
                this.drill.columnLabel = data.context?.column_label ?? dd.column_key;
                this.drill.message = data.context?.message ?? null;

                // Kolom RKO/RKAP: server kirim detail langsung (tanpa pivot).
                if (data.context?.direct_detail && data.detail) {
                    this.drill.direct = true;
                    this.drill.view = 'deep';
                    this.drill.deep = {
                        loading: false, error: null,
                        data: data.detail, html: this.buildDeepHtml(data.detail),
                        pb7: '', pb712: '', klasifikasi: '', value: this.drill.value,
                    };
                } else {
                    this.drill.pivot = data.pivot;
                }
            } catch (error) {
                this.drill.error = error.message;
            } finally {
                this.drill.loading = false;
            }
        },

        // Rincian LEBIH DALAM: klik nilai di pivot → posting biaya per Cost Element.
        async openDeep(pb7, pb712, klasifikasi, value) {
            if (!value || Math.abs(Number(value)) < 0.5) {
                return;
            }
            this.drill.view = 'deep';
            this.drill.deep = {
                loading: true, error: null, data: null, html: '',
                pb7: pb7 || '(Tanpa Sub Rekening)',
                pb712: pb712 || '(Tanpa Kode B)',
                klasifikasi: klasifikasi || '',
                value: Number(value || 0),
            };

            try {
                const params = new URLSearchParams({
                    type: 'LM16',
                    batch: this.filters.batch,
                    unit: this.filters.unit,
                    komoditi: this.filters.komoditi,
                    kode: this.drill.ctx.kode,
                    column: this.drill.ctx.column,
                });
                if (pb7 != null) params.set('pb7', pb7);
                if (pb712 != null) params.set('pb712', pb712);
                if (klasifikasi != null) params.set('klasifikasi', klasifikasi);
                const data = await this.fetchReport(`/report-data/drilldown-deep?${params.toString()}`);
                this.drill.deep.data = data.detail;
                this.drill.deep.html = this.buildDeepHtml(data.detail);
            } catch (error) {
                this.drill.deep.error = error.message;
            } finally {
                this.drill.deep.loading = false;
            }
        },

        backToPivot() {
            this.drill.view = 'pivot';
        },

        closeDrill() {
            this.drill.open = false;
        },

        fmtNum(value) {
            const n = Number(value ?? 0);
            if (!Number.isFinite(n) || Math.abs(n) < 0.005) {
                return '';
            }
            return n.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        },

        fmtInt(value) {
            const n = Number(value ?? 0);
            return Number.isFinite(n) ? n.toLocaleString('id-ID') : '0';
        },

        buildDeepHtml(detail) {
            if (!detail || !detail.row_count) return '';
            const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            const dash = '<span class="lm-dd-dash">–</span>';
            let out = '';

            for (const sec of (detail.sections ?? [])) {
                const cols = sec.columns ?? [];
                let head = '<tr><th class="lm-dd-n">#</th>';
                for (const col of cols) {
                    head += '<th class="' + (col.numeric ? 'lm-dd-n' : 'lm-dd-l') + '">' + esc(col.label) + '</th>';
                }
                head += '</tr>';

                let body = '';
                let i = 0;
                for (const row of (sec.rows ?? [])) {
                    i++;
                    let tr = '<tr><td class="lm-dd-n lm-dd-rownum">' + i + '</td>';
                    for (const col of cols) {
                        const v = row[col.field];
                        if (col.numeric) {
                            const f = this.fmtNum(v);
                            tr += '<td class="lm-dd-n">' + (f ? esc(f) : dash) + '</td>';
                        } else {
                            const has = v !== null && v !== undefined && v !== '';
                            tr += '<td class="lm-dd-l">' + (has ? esc(v) : dash) + '</td>';
                        }
                    }
                    body += tr + '</tr>';
                }

                let foot = '<tr class="lm-dd-subrow"><td class="lm-dd-n"></td>';
                for (const col of cols) {
                    let cell = '';
                    if (col.field === sec.value_field) {
                        cell = 'Subtotal: ' + this.fmtNum(sec.subtotal);
                    } else if (col.field === sec.qty_field) {
                        cell = 'Subtotal: ' + this.fmtNum(sec.qty_subtotal);
                    }
                    foot += '<td class="' + (col.numeric ? 'lm-dd-n' : 'lm-dd-l') + '">' + cell + '</td>';
                }
                foot += '</tr>';

                out += '<div class="lm-dd-section">'
                    + '<div class="lm-dd-section-head"><span class="lm-dd-section-name">' + esc(sec.label) + '</span>'
                    + '<span class="lm-dd-section-meta">' + this.fmtInt(sec.row_count) + ' baris · Rp ' + this.fmtNum(sec.subtotal) + '</span></div>'
                    + '<table class="lm-dd-table lm-dd-raw"><thead>' + head + '</thead><tbody>' + body + '</tbody>'
                    + '<tfoot>' + foot + '</tfoot></table></div>';
            }

            return out;
        },

        deepDragStart(event) {
            const el = this.$refs.deepScroll;
            if (!el || event.button !== 0) return;
            this._deepDrag = { active: true, startX: event.pageX, startY: event.pageY, left: el.scrollLeft, top: el.scrollTop };
            el.classList.add('lm-dd-dragging');
            event.preventDefault();
        },

        deepDragMove(event) {
            const d = this._deepDrag;
            if (!d || !d.active) return;
            const el = this.$refs.deepScroll;
            if (!el) return;
            el.scrollLeft = d.left - (event.pageX - d.startX);
            el.scrollTop = d.top - (event.pageY - d.startY);
        },

        deepDragEnd() {
            if (!this._deepDrag || !this._deepDrag.active) return;
            this._deepDrag.active = false;
            const el = this.$refs.deepScroll;
            if (el) el.classList.remove('lm-dd-dragging');
        }
    }
}
</script>
@endpush
@endsection
