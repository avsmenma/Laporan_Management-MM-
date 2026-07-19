{{-- Popup drill-down sumber data halaman Laba Rugi (2 tahap, gaya sama dgn /kebun & /pabrik).
     Sertakan di DALAM elemen x-data halaman, lalu spread window.lmLrDrillMixin() ke
     komponen Alpine halaman: return { ...lmLrDrillMixin(), ... }.
     Halaman cukup memanggil openDrill({title, columnLabel, unit, value, params}). --}}
<div class="lm-dd-overlay lm-dd-pks" x-show="drill.open" x-cloak
     @keydown.escape.window="drill.open && closeDrill()" @click.self="closeDrill()">
    <div class="lm-dd-modal">
        <div class="lm-dd-head">
            <button type="button" class="lm-dd-back" x-show="drill.view==='deep'" @click="backToPivot()" title="Kembali ke rincian">&larr;</button>
            <div class="lm-dd-titles">
                <div class="lm-dd-title" x-text="drill.title"></div>
                <div class="lm-dd-bc" x-show="drill.view==='pivot'">
                    <span class="lm-dd-chip" x-text="drill.columnLabel"></span>
                </div>
                <div class="lm-dd-bc" x-show="drill.view==='deep'">
                    <span style="display:flex;align-items:center;gap:10px">
                        <span class="lm-dd-chip" x-text="drill.deep.gLabel"></span>
                        <template x-if="drill.deep.rLabel">
                            <span style="display:flex;align-items:center;gap:10px"><span class="lm-dd-bc-sep">&rsaquo;</span><span class="lm-dd-chip" x-text="drill.deep.rLabel"></span></span>
                        </template>
                        <template x-if="drill.deep.cLabel">
                            <span style="display:flex;align-items:center;gap:10px"><span class="lm-dd-bc-sep">&rsaquo;</span><span class="lm-dd-chip" x-text="drill.deep.cLabel"></span></span>
                        </template>
                    </span>
                </div>
            </div>
            <button type="button" class="lm-dd-close" @click="closeDrill()" aria-label="Tutup">&times;</button>
        </div>

        <div class="lm-dd-body">
            <!-- TAHAP 1: pivot rincian sumber -->
            <div x-show="drill.view==='pivot'">
                <div x-show="drill.loading" class="lm-dd-state">Memuat rincian sumber…</div>
                <div x-show="!drill.loading && drill.error" class="lm-dd-state lm-dd-err" x-text="drill.error"></div>
                <div x-show="!drill.loading && !drill.error && (!drill.pivot || !drill.pivot.row_count)" class="lm-dd-state">
                    Tidak ada baris sumber mentah untuk sel ini pada periode terpilih.
                </div>

                <div class="lm-dd-hint" x-show="!drill.loading && !drill.error && drill.pivot && drill.pivot.row_count">
                    Klik salah satu angka untuk melihat data sumber apa adanya (rincian per baris transaksi).
                </div>
                <div class="lm-dd-tablewrap" x-show="!drill.loading && !drill.error && drill.pivot && drill.pivot.row_count">
                    <template x-if="drill.pivot && drill.pivot.row_count">
                        <table class="lm-dd-table">
                            <thead>
                                <tr>
                                    <th class="lm-dd-l" x-text="drill.pivot.col1"></th>
                                    <th class="lm-dd-l" x-text="drill.pivot.col2"></th>
                                    <template x-for="(cat, ci) in (drill.pivot?.categories ?? [])" :key="ci">
                                        <th class="lm-dd-n" x-text="cat"></th>
                                    </template>
                                    <th class="lm-dd-n">Grand Total</th>
                                </tr>
                            </thead>
                            <template x-for="(group, gi) in (drill.pivot?.groups ?? [])" :key="gi">
                                <tbody>
                                    <template x-for="(r, ri) in group.rows" :key="ri">
                                        <tr>
                                            <td class="lm-dd-l lm-dd-pb7"><span class="lm-dd-dot" x-show="ri === 0"></span><span x-text="ri === 0 ? group.label : ''"></span></td>
                                            <td class="lm-dd-l"><span class="lm-dd-code" x-text="r.label"></span></td>
                                            <template x-for="ck in drill.pivot.cat_keys" :key="ck">
                                                <td class="lm-dd-n" :class="{ 'lm-dd-clickable': r.values[ck] }"
                                                    @click="openDeepCell(group, r, ck, r.values[ck])"
                                                    x-text="fmtDd(r.values[ck])"></td>
                                            </template>
                                            <td class="lm-dd-n lm-dd-rowtot lm-dd-clickable"
                                                @click="openDeepCell(group, r, null, r.total)"
                                                x-text="fmtDd(r.total)"></td>
                                        </tr>
                                    </template>
                                    <tr class="lm-dd-subrow">
                                        <td class="lm-dd-l" colspan="2" x-text="group.label + ' Total'"></td>
                                        <template x-for="ck in drill.pivot.cat_keys" :key="ck">
                                            <td class="lm-dd-n" x-text="fmtDd(group.subtotal[ck])"></td>
                                        </template>
                                        <td class="lm-dd-n" x-text="fmtDd(group.subtotal_total)"></td>
                                    </tr>
                                </tbody>
                            </template>
                        </table>
                    </template>
                </div>
            </div>

            <!-- TAHAP 2: data sumber mentah apa adanya -->
            <div x-show="drill.view==='deep'">
                <div x-show="drill.deep.loading" class="lm-dd-state">Memuat rincian lebih dalam…</div>
                <div x-show="!drill.deep.loading && drill.deep.error" class="lm-dd-state lm-dd-err" x-text="drill.deep.error"></div>
                <div x-show="!drill.deep.loading && !drill.deep.error && (!drill.deep.data || !drill.deep.data.row_count)" class="lm-dd-state">
                    Tidak ada rincian lebih dalam untuk sel ini.
                </div>
                <!-- Tabel dibangun sebagai HTML statis (bukan x-for) agar tetap lancar
                     walau baris sangat banyak; geser kiri/kanan dengan tahan-klik (drag). -->
                <div class="lm-dd-tablewrap lm-dd-drag"
                     x-show="!drill.deep.loading && !drill.deep.error && drill.deep.data && drill.deep.data.row_count"
                     x-ref="deepScroll"
                     @mousedown="deepDragStart($event)"
                     @mousemove.window="deepDragMove($event)"
                     @mouseup.window="deepDragEnd()"
                     x-html="drill.deep.html"></div>
            </div>
        </div>

        <div class="lm-dd-foot" x-show="drill.footTotal">
            <div class="lm-dd-foot-stat"><strong x-text="drill.footCount"></strong></div>
            <div class="lm-dd-foot-total">
                <span class="lm-dd-foot-label">Grand Total</span>
                <span class="lm-dd-foot-amount" x-text="drill.footTotal"></span>
            </div>
        </div>
    </div>
</div>

<style>
    /* Sel tabel utama yang bisa dirinci (drill-down) */
    .lm-report-table .tabulator-cell.lm-cell-drill { cursor: pointer; }
</style>

@push('scripts')
<script>
// Mixin drill-down bersama halaman Laba Rugi. Tahap 1 memanggil
// /report-data/laba-rugi/drilldown (pivot), tahap 2 /drilldown-deep (mentah).
function lmLrDrillMixin() {
    return {
        drill: {
            open: false, view: 'pivot', loading: false, error: null,
            title: '', columnLabel: '', unit: 'Rp', value: 0,
            pivot: null, footCount: '', footTotal: '', params: null,
            deep: { loading: false, error: null, data: null, html: '', gLabel: '', rLabel: '', cLabel: '' },
        },

        // Format popup: 0 → kosong; negatif dalam kurung (pola halaman Laba Rugi).
        fmtDd(v) {
            const n = Number(v ?? 0);
            if (!Number.isFinite(n) || Math.abs(n) < 0.005) return '';
            const s = Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            return n < 0 ? '(' + s + ')' : s;
        },
        fmtIntDd(v) {
            const n = Number(v ?? 0);
            return Number.isFinite(n) ? n.toLocaleString('id-ID') : '0';
        },
        ddMoney(v) {
            return (this.drill.unit ? this.drill.unit + ' ' : '') + (this.fmtDd(v) || '0');
        },
        async ddFetch(url, params) {
            const qs = new URLSearchParams();
            Object.entries(params || {}).forEach(([k, v]) => {
                if (v !== null && v !== undefined) qs.set(k, v);
            });
            const resp = await fetch(url + '?' + qs.toString(), { headers: { 'Accept': 'application/json' } });
            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                throw new Error(err.message || ('HTTP ' + resp.status));
            }
            return await resp.json();
        },

        // TAHAP 1 — dipanggil handler klik sel halaman.
        async openDrill(opts) {
            this.drill = {
                open: true, view: 'pivot', loading: true, error: null,
                title: opts.title, columnLabel: opts.columnLabel,
                unit: opts.unit ?? 'Rp', value: Number(opts.value ?? 0),
                pivot: null, footCount: '', footTotal: '', params: opts.params,
                deep: { loading: false, error: null, data: null, html: '', gLabel: '', rLabel: '', cLabel: '' },
            };
            try {
                const data = await this.ddFetch('/report-data/laba-rugi/drilldown', opts.params);
                this.drill.pivot = data.pivot || null;
                if (this.drill.pivot && this.drill.pivot.row_count) {
                    this.drill.footCount = this.fmtIntDd(this.drill.pivot.row_count) + ' baris data';
                    this.drill.footTotal = this.ddMoney(this.drill.pivot.grand_total);
                }
            } catch (e) {
                this.drill.error = e.message;
            } finally {
                this.drill.loading = false;
            }
        },

        // TAHAP 2 — klik angka pada pivot; ck null = total baris (semua bulan cakupan).
        async openDeepCell(group, row, ck, value) {
            if (!value || Math.abs(Number(value)) < 0.005) return;
            this.drill.view = 'deep';
            this.drill.deep = {
                loading: true, error: null, data: null, html: '',
                gLabel: group.label, rLabel: row.label,
                cLabel: ck != null ? (this.drill.pivot.categories[this.drill.pivot.cat_keys.indexOf(ck)] ?? String(ck)) : '',
            };
            try {
                const params = { ...this.drill.params, g: group.g, r: row.r };
                if (ck != null) params.c = ck;
                const data = await this.ddFetch('/report-data/laba-rugi/drilldown-deep', params);
                this.drill.deep.data = data.detail || null;
                this.drill.deep.html = this.buildDeepHtml(data.detail);
                this.drill.footCount = this.fmtIntDd(data.detail?.row_count ?? 0) + ' baris data';
                this.drill.footTotal = this.ddMoney(value);
            } catch (e) {
                this.drill.deep.error = e.message;
            } finally {
                this.drill.deep.loading = false;
            }
        },

        backToPivot() {
            this.drill.view = 'pivot';
            if (this.drill.pivot && this.drill.pivot.row_count) {
                this.drill.footCount = this.fmtIntDd(this.drill.pivot.row_count) + ' baris data';
                this.drill.footTotal = this.ddMoney(this.drill.pivot.grand_total);
            }
        },
        closeDrill() {
            this.drill.open = false;
        },

        // Bangun HTML tabel rincian dalam sekali jalan (string statis) supaya popup
        // tetap lancar walau baris sangat banyak — tanpa overhead reaktif per sel.
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
                            const f = this.fmtDd(v);
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
                        cell = 'Subtotal: ' + this.fmtDd(sec.subtotal);
                    } else if (sec.qty_field && col.field === sec.qty_field) {
                        cell = 'Subtotal: ' + this.fmtDd(sec.qty_subtotal);
                    }
                    foot += '<td class="' + (col.numeric ? 'lm-dd-n' : 'lm-dd-l') + '">' + cell + '</td>';
                }
                foot += '</tr>';

                out += '<div class="lm-dd-section">'
                    + '<div class="lm-dd-section-head"><span class="lm-dd-section-name">' + esc(sec.label) + '</span>'
                    + '<span class="lm-dd-section-meta">' + this.fmtIntDd(sec.row_count) + ' baris · ' + this.ddMoney(sec.subtotal) + '</span></div>'
                    + '<table class="lm-dd-table lm-dd-raw"><thead>' + head + '</thead><tbody>' + body + '</tbody>'
                    + '<tfoot>' + foot + '</tfoot></table></div>';
            }

            return out;
        },

        // Geser tabel rincian dalam kiri/kanan dengan tahan-klik (drag-to-pan).
        deepDragStart(event) {
            const el = this.$refs.deepScroll;
            if (!el || event.button !== 0) return;
            this._deepDrag = { active: true, startX: event.pageX, left: el.scrollLeft };
            el.classList.add('lm-dd-dragging');
            event.preventDefault();
        },
        deepDragMove(event) {
            const d = this._deepDrag;
            if (!d || !d.active) return;
            const el = this.$refs.deepScroll;
            if (!el) return;
            el.scrollLeft = d.left - (event.pageX - d.startX);
        },
        deepDragEnd() {
            if (!this._deepDrag || !this._deepDrag.active) return;
            this._deepDrag.active = false;
            const el = this.$refs.deepScroll;
            if (el) el.classList.remove('lm-dd-dragging');
        },
    };
}
</script>
@endpush
