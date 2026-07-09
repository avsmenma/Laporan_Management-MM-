import './bootstrap';

import Alpine from 'alpinejs';
import { TabulatorFull as Tabulator } from 'tabulator-tables';
import 'tabulator-tables/dist/css/tabulator_semanticui.min.css';

window.Alpine = Alpine;
window.Tabulator = Tabulator;

const valueColumns = new Set([
    'real_bulan_lalu',
    'real_thn_lalu',
    'bi_jumlah',
    'bi_rko',
    'bi_rkap',
    'sd_real_thn_lalu',
    'sd_jumlah',
    'sd_rko',
    'sd_rkap',
    'real_bln_lalu',
    'bi_olah',
    'bi_kso',
    'sd_olah',
    'sd_kso',
    'cap_bi_lalu',
    'cap_bi_thnlalu',
    'cap_bi_rko',
    'cap_bi_rkap',
    'cap_sd_lalu',
    'cap_sd_rko',
    'cap_sd_rkap',
    'rp_kg_tbs',
    'rp_kg_mi',
]);

const MONTH_NAMES = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

function monthLabel(meta) {
    const period = Number(meta?.batch?.period ?? 0);
    const name = MONTH_NAMES[period] ?? '';
    return name ? `Bulan ${name}` : 'Bulan';
}

// LM13: kolom "WBS/GL/CC" pada spreadsheet hanya memuat huruf bagian (A., B., ...).
// Kode internal (PLSM, PHTG, =L2, "Beban Produksi", "-") tidak ada di spreadsheet,
// jadi dikosongkan agar tampilan identik.
function cleanLm13Kode(kode) {
    const k = String(kode ?? '').trim();
    return /^[A-Z]\.$/.test(k) ? k : '';
}

function isPercent(field) {
    return field.includes('cap_') || field.includes('rendemen');
}

function formatNumber(value, field, percent = isPercent(field)) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number) || Math.abs(number) < 0.000001) {
        return '-';
    }

    // Nilai dibulatkan tanpa desimal; hanya persentase (capaian) & rendemen yang 2 desimal.
    const fractionDigits = percent ? 2 : 0;
    return number.toLocaleString('id-ID', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    });
}

// Mode "compact" dipakai saat layar penuh: kolom & font dikecilkan supaya semua
// kolom muat tanpa CSS zoom (zoom merusak rendering header Tabulator).
let COMPACT = false;
function cw(normal, compact) {
    return COMPACT ? compact : normal;
}

// Tinggi tabel agar memenuhi sisa layar saat mode fokus (tanpa zoom).
function focusHeight(element) {
    const top = element.getBoundingClientRect().top;
    return `${Math.max(window.innerHeight - top - 40, 320)}px`;
}

function textColumn(field, title, width) {
    return {
        title,
        field,
        // Tanpa lebar tetap: biarkan Tabulator menyesuaikan dengan isi (fitData).
        // minWidth hanya lantai aman; uraian tumbuh penuh mengikuti teks terpanjang.
        minWidth: field === 'uraian' ? cw(200, 150) : cw(76, 60),
        frozen: true,
        headerSort: false,
        formatter(cell) {
            const data = cell.getRow().getData();
            const value = cell.getValue() ?? '';
            const indent = field === 'uraian' ? Number(data.indent ?? 0) * 14 : 0;
            const title = String(value).replace(/"/g, '&quot;');

            return `<span title="${title}" style="display:block;padding-left:${indent}px">${value}</span>`;
        },
    };
}

// Aturan warna kolom Capaian (%) khusus baris PRODUKSI LM16 (permintaan user):
//  - 'redHigh'  (stok): >= 100 MERAH, < 100 HIJAU — stok menumpuk itu buruk.
//  - 'greenHigh' (TBS masuk/diolah, Minyak/Inti Sawit, Rendemen): >= 100 HIJAU, < 100 MERAH.
// Baris lain (biaya, subtotal) memakai aturan default: merah hanya bila >= 100,01.
function lm16CapaianRule(uraian) {
    const u = String(uraian ?? '').trim().toLowerCase();
    if (u.startsWith('stok awal tbs') || u.startsWith('stok akhir tbs')) {
        return 'redHigh';
    }
    if (u.includes('rendemen')
        || u.startsWith('tbs dari lapangan')
        || u.startsWith('tbs di olah')
        || u === 'minyak sawit'
        || u === 'inti sawit') {
        return 'greenHigh';
    }
    return null;
}

// clickable=false → sel tidak membuka popup sumber data & tidak tampak bisa diklik.
// Dipakai LM13 yang tak punya rincian sumber mentah (drilldown hanya untuk LM14).
// lm16Cap=true → kolom Capaian (%) LM16: warna per aturan baris (lm16CapaianRule).
function numberColumn(field, title, clickable = true, lm16Cap = false) {
    const column = {
        title,
        field,
        // Tanpa lebar tetap: Tabulator (fitData) tumbuh mengikuti angka terpanjang.
        // minWidth = lantai aman yang sudah memuat nilai rupiah miliaran sekalipun.
        minWidth: cw(120, 84),
        hozAlign: 'right',
        headerHozAlign: 'center',
        headerSort: false,
        cssClass: clickable ? 'lm-number-cell' : 'lm-number-cell lm-number-static',
        formatter(cell) {
            const row = cell.getRow().getData();
            // Baris judul seksi (header, mis. "D. MINYAK SAWIT") & sub-judul
            // (mis. "Minyak Sawit" di seksi Produksi Hasil Olah) hanya label —
            // tidak menampilkan nilai, kosong (bukan strip "-"). Sesuai acuan Excel.
            if (row.row_type === 'header' || row.row_type === 'subheader') {
                return '';
            }

            // Capaian (%):
            //  - Kolom terhadap RKO/RKAP: tak ada anggaran DAN tak ada realisasi → "-";
            //    tak ada anggaran tapi ADA realisasi → sentinel 100,01 merah.
            //  - Warna: baris produksi LM16 ikut lm16CapaianRule (hijau/merah per baris);
            //    baris lain default — merah hanya bila >= 100,01.
            const capRule = lm16Cap ? lm16CapaianRule(row.uraian) : null;
            const isBudgetCap = /^cap_.*(rko|rkap)$/.test(field);
            if (isBudgetCap || (capRule !== null && field.startsWith('cap_'))) {
                // Pada baris subtotal/total (latar hijau gelap), angka berwarna nyaris
                // hilang. Bungkus dengan "chip" latar putih agar kontras tetap tinggi;
                // baris biasa (putih) cukup teks berwarna polos.
                const darkRow = row.row_type === 'subtotal' || row.row_type === 'total';
                const paint = (t, color) => darkRow
                    ? `<span style="color:${color};font-weight:700;background:#fff;border-radius:5px;padding:0 6px;display:inline-block;line-height:1.45">${t}</span>`
                    : `<span style="color:${color};font-weight:600">${t}</span>`;
                const red = (t) => paint(t, '#c0392b');
                const green = (t) => paint(t, '#1e8449');
                if (isBudgetCap) {
                    const base = field.slice(4);                       // cap_bi_rko → bi_rko
                    const denom = Number(row[base] ?? 0);             // nilai RKO/RKAP
                    const real = Number(row[base.split('_')[0] + '_jumlah'] ?? 0); // bi_jumlah / sd_jumlah (Real)
                    if (Math.abs(denom) < 0.000001) {
                        // Tak ada RKO/RKAP: strip bila tak ada realisasi; sentinel tetap
                        // MERAH di semua baris (penanda anggaran belum ada, bukan capaian).
                        return Math.abs(real) < 0.000001 ? '-' : red('100,01');
                    }
                }
                const pct = Number(cell.getValue() ?? 0);
                if (Math.abs(pct) < 0.000001) {
                    return '-';
                }
                const text = pct.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                if (capRule === 'greenHigh') {
                    return pct >= 100 ? green(text) : red(text);
                }
                if (capRule === 'redHigh') {
                    return pct >= 100 ? red(text) : green(text);
                }
                return pct >= 100.01 ? red(text) : text;
            }

            const isRendemenRow = String(row.uraian ?? '').toLowerCase().includes('rendemen');
            // Baris Luas Area (Ha) ditampilkan 2 desimal (mis. 3.323,76).
            const isAreaRow = row.row_type === 'area' || row.row_type === 'area-total';

            return formatNumber(cell.getValue(), field, isPercent(field) || isRendemenRow || isAreaRow);
        },
    };

    if (clickable) {
        column.cellClick = function (event, cell) {
            const data = cell.getRow().getData();
            const meta = data.__cells?.[field]?.drilldown;
            if (!meta || Math.abs(Number(cell.getValue() ?? 0)) < 0.000001) {
                return;
            }

            cell.getElement().dispatchEvent(new CustomEvent('lm-cell-click', {
                bubbles: true,
                detail: {
                    row: data,
                    field,
                    value: Number(cell.getValue() ?? 0),
                    drilldown: meta,
                },
            }));
        };
    }

    return column;
}

function baseColumns(includeKode = true) {
    return [
        ...(includeKode ? [textColumn('kode', 'WBS/GL/CC', 110)] : []),
        textColumn('uraian', 'Uraian', 320),
    ];
}

function normalizeRows(rows) {
    return rows.map((row) => ({
        ...row,
        __cells: row.cells ?? {},
    }));
}

function lm14Columns(meta) {
    return [
        ...baseColumns(),
        {
            title: monthLabel(meta),
            columns: [
                numberColumn('bi_jumlah', 'Real Bln Ini'),
                numberColumn('real_bulan_lalu', 'Real Bln Lalu'),
                numberColumn('real_thn_lalu', 'Real Thn Lalu'),
                numberColumn('bi_rko', 'RKO'),
                numberColumn('bi_rkap', 'RKAP'),
                numberColumn('cap_bi_lalu', '% Bln Lalu'),
                numberColumn('cap_bi_thnlalu', '% Thn Lalu'),
                numberColumn('cap_bi_rko', '% RKO'),
                numberColumn('cap_bi_rkap', '% RKAP'),
            ],
        },
        {
            title: `s.d ${monthLabel(meta)}`,
            columns: [
                numberColumn('sd_jumlah', 'Real s.d'),
                numberColumn('sd_real_thn_lalu', 'Thn Lalu'),
                numberColumn('sd_rko', 'RKO'),
                numberColumn('sd_rkap', 'RKAP'),
                numberColumn('cap_sd_lalu', '% Thn Lalu'),
                numberColumn('cap_sd_rko', '% RKO'),
                numberColumn('cap_sd_rkap', '% RKAP'),
            ],
        },
    ];
}

const lm13Blocks = [
    ['OLAH_JUAL', 'Kebun Sendiri + Pihak III'],
    ['OLAH', 'Di Olah'],
    ['JUAL', 'Di Jual'],
];

// Karet hanya punya 1 blok ("Kebun Sendiri + Pihak III") di acuan -B; sawit 3 blok.
function lm13BlocksFor(komoditi) {
    return String(komoditi ?? '').toUpperCase() === 'KR'
        ? [['OLAH_JUAL', 'Kebun Sendiri + Pihak III']]
        : lm13Blocks;
}

// Baris header "Luas Area Kebun" (TM Inti / TM Plasma/Pihak III / Jumlah) di atas
// tabel LM13 — mengikuti sheet {kebun}-B (baris luas area). Nilai dari meta.area
// (alokasi_areal), sama untuk Bulan Ini & s.d, dan diulang di semua blok.
// Plasma/Pihak III belum dipisah di sumber data → 0; Jumlah = Inti + Plasma.
function lm13AreaRows(meta) {
    const a = meta?.area;
    if (!a) {
        return [];
    }

    const inti = { lalu: Number(a.real_thn_lalu ?? 0), ini: Number(a.real_thn_ini ?? 0), rko: Number(a.rko ?? 0), rkap: Number(a.rkap ?? 0) };
    const plasma = { lalu: 0, ini: 0, rko: 0, rkap: 0 };
    const jumlah = { lalu: inti.lalu + plasma.lalu, ini: inti.ini + plasma.ini, rko: inti.rko + plasma.rko, rkap: inti.rkap + plasma.rkap };

    const blocks = lm13BlocksFor(meta?.unit?.komoditi);
    const makeRow = (urutan, uraian, v, rowType) => {
        const row = { urutan, kode: '', uraian, row_type: rowType, indent: 0, __cells: {} };
        for (const [block] of blocks) {
            row[`${block}_real_thn_lalu`] = v.lalu;
            row[`${block}_bi_jumlah`] = v.ini;
            row[`${block}_bi_rko`] = v.rko;
            row[`${block}_bi_rkap`] = v.rkap;
            row[`${block}_sd_real_thn_lalu`] = v.lalu;
            row[`${block}_sd_jumlah`] = v.ini;
            row[`${block}_sd_rko`] = v.rko;
            row[`${block}_sd_rkap`] = v.rkap;
        }
        return row;
    };

    return [
        makeRow(-3, 'Luas Area Kebun TM Inti', inti, 'area'),
        makeRow(-2, 'Luas Area Kebun TM Plasma/Pihak III', plasma, 'area'),
        makeRow(-1, 'Jumlah', jumlah, 'area-total'),
    ];
}

function pivotLm13Rows(rows, meta) {
    const grouped = new Map();

    for (const row of rows) {
        const key = row.urutan;
        const block = row.block;
        const target = grouped.get(key) ?? {
            urutan: row.urutan,
            kode: cleanLm13Kode(row.kode),
            uraian: row.uraian,
            row_type: row.row_type,
            indent: row.indent,
            __cells: {},
        };

        for (const field of ['real_thn_lalu', 'bi_jumlah', 'bi_rko', 'bi_rkap', 'sd_real_thn_lalu', 'sd_jumlah', 'sd_rko', 'sd_rkap']) {
            target[`${block}_${field}`] = row[field] ?? 0;
            if (row.cells?.[field]) {
                target.__cells[`${block}_${field}`] = row.cells[field];
            }
        }

        grouped.set(key, target);
    }

    const dataRows = [...grouped.values()].sort((left, right) => left.urutan - right.urutan);

    // Sisipkan baris luas area di paling atas (urutan negatif), persis seperti Excel.
    return [...lm13AreaRows(meta), ...dataRows];
}

function lm13Columns(meta) {
    return [
        ...baseColumns(),
        ...lm13BlocksFor(meta?.unit?.komoditi).map(([block, title]) => ({
            title,
            columns: [
                {
                    title: monthLabel(meta),
                    columns: [
                        numberColumn(`${block}_real_thn_lalu`, 'Real Thn Lalu', false),
                        numberColumn(`${block}_bi_jumlah`, 'Real Bln Ini', false),
                        numberColumn(`${block}_bi_rko`, 'RKO', false),
                        numberColumn(`${block}_bi_rkap`, 'RKAP', false),
                    ],
                },
                {
                    title: `s.d ${monthLabel(meta)}`,
                    columns: [
                        numberColumn(`${block}_sd_real_thn_lalu`, 'Real Thn Lalu', false),
                        numberColumn(`${block}_sd_jumlah`, 'Real s.d', false),
                        numberColumn(`${block}_sd_rko`, 'RKO', false),
                        numberColumn(`${block}_sd_rkap`, 'RKAP', false),
                    ],
                },
            ],
        })),
    ];
}

function lm16Columns(meta) {
    return [
        ...baseColumns(),
        numberColumn('real_bln_lalu', 'Realisasi Bln Lalu'),
        {
            title: monthLabel(meta),
            columns: [
                numberColumn('bi_olah', 'Olah'),
                numberColumn('bi_kso', 'KSO'),
                numberColumn('bi_jumlah', 'Jumlah'),
            ],
        },
        {
            title: `${monthLabel(meta)} Budget`,
            columns: [
                numberColumn('bi_rko', 'RKO'),
                numberColumn('bi_rkap', 'RKAP'),
            ],
        },
        {
            title: `s.d ${monthLabel(meta)}`,
            columns: [
                numberColumn('sd_olah', 'Olah'),
                numberColumn('sd_kso', 'KSO'),
                numberColumn('sd_jumlah', 'Jumlah'),
            ],
        },
        {
            title: `s.d ${monthLabel(meta)} Budget`,
            columns: [
                numberColumn('sd_rko', 'RKO'),
                numberColumn('sd_rkap', 'RKAP'),
            ],
        },
        {
            title: 'Capaian (%)',
            columns: [
                numberColumn('cap_bi_lalu', 'BI/Lalu', true, true),
                numberColumn('cap_bi_rko', 'BI/RKO', true, true),
                numberColumn('cap_bi_rkap', 'BI/RKAP', true, true),
                numberColumn('cap_sd_rko', 'S.D BI/RKO', true, true),
                numberColumn('cap_sd_rkap', 'S.D BI/RKAP', true, true),
            ],
        },
        {
            title: `Harga Pokok ${monthLabel(meta)}`,
            columns: [
                numberColumn('rp_kg_tbs', 'Rp/kg TBS'),
                numberColumn('rp_kg_mi', 'Rp/kg M+I'),
            ],
        },
        {
            title: `Harga Pokok s.d ${monthLabel(meta)}`,
            columns: [
                numberColumn('rp_kg_tbs_sd', 'Rp/kg TBS'),
                numberColumn('rp_kg_mi_sd', 'Rp/kg M+I'),
            ],
        },
    ];
}

// =====================================================================
// LM Investasi (/kebun/investasi) — tabel dinamis dari kontrak API.
// Kolom dibangun langsung dari reportData.columns (bukan hardcode):
//  - kolom identitas (group === null, frozen) → teks kiri, dibekukan;
//  - kolom nilai (punya `group`) → angka kanan, digabung ke grouped header
//    per `group` yang berurutan.
// Desimal: key diawali `cap_` atau memuat `_rp_` → 2 desimal; selain itu 0.
// =====================================================================
function investasiDecimals(key) {
    const k = String(key ?? '');
    return (k.startsWith('cap_') || k.includes('_rp_')) ? 2 : 0;
}

function formatInvestasiNumber(value, decimals) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number) || Math.abs(number) < 0.000001) {
        return '-';
    }
    return number.toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function investasiTextColumn(col) {
    const center = col.key === 'tahun_tanam';
    return {
        title: col.title,
        field: col.key,
        minWidth: col.key === 'kebun' ? cw(160, 120) : cw(84, 64),
        frozen: !!col.frozen,
        headerSort: false,
        hozAlign: center ? 'center' : 'left',
        headerHozAlign: center ? 'center' : 'left',
        formatter(cell) {
            const value = cell.getValue();
            if (value === null || value === undefined || value === '') {
                return '';
            }
            // HTML-encode (nilai berasal dari data impor Excel) — cegah XSS.
            const safe = String(value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            return `<span title="${safe}">${safe}</span>`;
        },
    };
}

function investasiNumberColumn(col) {
    const decimals = investasiDecimals(col.key);
    // Hormati minWidth per-kolom dari kontrak API (mis. 'Borrowing Cost' butuh 120px
    // agar judul muat satu baris & sejajar dengan header lain); default 110/80.
    const min = Number.isFinite(Number(col.minWidth)) ? Number(col.minWidth) : cw(110, 80);
    return {
        title: col.title,
        field: col.key,
        minWidth: min,
        hozAlign: 'right',
        headerHozAlign: 'center',
        headerSort: false,
        cssClass: 'lm-number-cell lm-number-static',
        formatter(cell) {
            const row = cell.getRow().getData();
            if (row.row_type === 'header' || row.row_type === 'subheader') {
                return '';
            }
            return formatInvestasiNumber(cell.getValue(), decimals);
        },
    };
}

// Gabungkan kolom nilai yang berurutan dengan `group` sama menjadi satu
// grouped header Tabulator; kolom identitas (group null) tetap top-level.
function buildInvestasiColumns(columns) {
    const out = [];
    let i = 0;
    while (i < columns.length) {
        const col = columns[i];
        if (col.group === null || col.group === undefined) {
            out.push(investasiTextColumn(col));
            i += 1;
            continue;
        }
        const group = col.group;
        const children = [];
        while (i < columns.length && columns[i].group === group) {
            children.push(investasiNumberColumn(columns[i]));
            i += 1;
        }
        out.push({ title: group, columns: children });
    }
    return out;
}

function renderInvestasi(element, reportData) {
    const payload = reportData ?? { rows: [], columns: [], meta: {} };
    const rows = Array.isArray(payload.rows) ? payload.rows : [];
    const columns = Array.isArray(payload.columns) ? payload.columns : [];
    // Deteksi mode fokus saat render: kolom compact + tinggi penuh layar.
    COMPACT = document.body.classList.contains('lm-focus');
    element.innerHTML = '';

    return new Tabulator(element, {
        data: rows,
        columns: buildInvestasiColumns(columns),
        height: COMPACT ? focusHeight(element) : '65vh',
        layout: 'fitData',
        columnHeaderVertAlign: 'bottom',
        movableColumns: false,
        rowFormatter,
        placeholder: 'Tidak ada baris laporan',
    });
}

function tableRows(reportType, rows, meta) {
    return reportType === 'LM13' ? pivotLm13Rows(rows, meta) : normalizeRows(rows);
}

function tableColumns(reportType, meta) {
    if (reportType === 'LM14') {
        return lm14Columns(meta);
    }

    if (reportType === 'LM13') {
        return lm13Columns(meta);
    }

    return lm16Columns(meta);
}

function rowFormatter(row) {
    const data = row.getData();
    const element = row.getElement();
    element.classList.toggle('lm-row-header', data.row_type === 'header');
    element.classList.toggle('lm-row-subheader', data.row_type === 'subheader');
    element.classList.toggle('lm-row-subtotal', data.row_type === 'subtotal');
    element.classList.toggle('lm-row-total', data.row_type === 'total');
    element.classList.toggle('lm-row-area', data.row_type === 'area' || data.row_type === 'area-total');
    element.classList.toggle('lm-row-area-total', data.row_type === 'area-total');
}

function renderTable(element, reportType, reportData) {
    const payload = reportData ?? { rows: [], meta: {} };
    const rows = tableRows(reportType, Array.isArray(payload.rows) ? payload.rows : [], payload.meta ?? {});
    // Deteksi mode fokus saat render: kolom compact + tinggi penuh layar.
    COMPACT = document.body.classList.contains('lm-focus');
    element.innerHTML = '';

    return new Tabulator(element, {
        data: rows,
        columns: tableColumns(reportType, payload.meta ?? {}),
        height: COMPACT ? focusHeight(element) : '65vh',
        // fitData: Tabulator mengukur isi sel sebenarnya lalu menyesuaikan lebar tiap
        // kolom (pakai font yang benar-benar dirender), jadi tak ada teks/angka terpotong.
        // Tabel menggulir mendatar bila total lebar melebihi layar.
        layout: 'fitData',
        columnHeaderVertAlign: 'bottom',
        movableColumns: false,
        rowFormatter,
        placeholder: 'Tidak ada baris laporan',
    });
}

function applySearch(table, term) {
    if (!table) {
        return;
    }

    const text = String(term ?? '').trim().toLowerCase();
    if (text === '') {
        table.clearFilter(true);
        return;
    }

    table.setFilter((row) => {
        return String(row.kode ?? '').toLowerCase().includes(text)
            || String(row.uraian ?? '').toLowerCase().includes(text);
    });
}

function flattenColumns(columns, parents = []) {
    return columns.flatMap((column) => {
        const title = [...parents, column.title].filter(Boolean).join(' - ');
        if (column.columns) {
            return flattenColumns(column.columns, [...parents, column.title]);
        }

        return [{ field: column.field, title }];
    }).filter((column) => column.field);
}

function exportRows(table) {
    const columns = flattenColumns(table.getColumnDefinitions());
    const rows = table.getData('active');

    return { columns, rows };
}

function csvEscape(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportCsv(table, filename) {
    const { columns, rows } = exportRows(table);
    const lines = [
        columns.map((column) => csvEscape(column.title)).join(','),
        ...rows.map((row) => columns.map((column) => csvEscape(row[column.field])).join(',')),
    ];

    downloadBlob(lines.join('\n'), filename, 'text/csv;charset=utf-8');
}

function exportExcel(table, filename) {
    const { columns, rows } = exportRows(table);
    const header = columns.map((column) => `<th>${column.title}</th>`).join('');
    const body = rows.map((row) => `<tr>${columns.map((column) => `<td>${row[column.field] ?? ''}</td>`).join('')}</tr>`).join('');
    const html = `<html><head><meta charset="utf-8"></head><body><table border="1"><thead><tr>${header}</tr></thead><tbody>${body}</tbody></table></body></html>`;

    downloadBlob(html, filename, 'application/vnd.ms-excel;charset=utf-8');
}

function exportPdf(table, title) {
    const { columns, rows } = exportRows(table);
    const header = columns.map((column) => `<th>${column.title}</th>`).join('');
    const body = rows.map((row) => `<tr>${columns.map((column) => `<td>${row[column.field] ?? ''}</td>`).join('')}</tr>`).join('');
    const win = window.open('', '_blank', 'noopener,noreferrer');
    if (!win) {
        return;
    }

    win.document.write(`<html><head><title>${title}</title><style>body{font-family:Arial,sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:4px;text-align:right}th:nth-child(1),th:nth-child(2),td:nth-child(1),td:nth-child(2){text-align:left}</style></head><body><h1>${title}</h1><table><thead><tr>${header}</tr></thead><tbody>${body}</tbody></table></body></html>`);
    win.document.close();
    win.print();
}

function downloadBlob(content, filename, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

window.LmReportTables = {
    renderTable,
    renderInvestasi,
    applySearch,
    exportCsv,
    exportExcel,
    exportPdf,
    valueColumns,
};

window.lmDemoTable = () => ({
    initTable() {
        if (!this.$refs?.table) {
            return;
        }

        new Tabulator(this.$refs.table, {
            data: [],
            columns: [textColumn('kode', 'Kode', 110), textColumn('uraian', 'Uraian', 320)],
            placeholder: 'Gunakan menu Kebun atau Pabrik untuk tabel LM final.',
        });
    },
});

Alpine.start();
