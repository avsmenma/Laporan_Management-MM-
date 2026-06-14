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
    'cap_bi_sd',
    'rp_kg_tbs',
    'rp_kg_mi',
]);

function monthLabel(meta) {
    return `Bulan ${meta?.batch?.period ?? ''}`.trim();
}

function isPercent(field) {
    return field.includes('cap_') || field.includes('rendemen');
}

function formatNumber(value, field, percent = isPercent(field)) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number) || Math.abs(number) < 0.000001) {
        return '-';
    }

    const fractionDigits = percent ? 1 : 0;
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
        width: field === 'uraian' ? cw(width, 240) : cw(width, 64),
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

function numberColumn(field, title, minWidth = 116) {
    return {
        title,
        field,
        // Lebar tetap supaya kolom nilai tidak menyusut & angka tidak tumpang tindih.
        // Compact (layar penuh): lebar pas untuk angka rupiah terpanjang.
        ...(COMPACT ? { width: 96 } : { minWidth }),
        hozAlign: 'right',
        headerHozAlign: 'center',
        headerSort: false,
        cssClass: 'lm-number-cell',
        formatter(cell) {
            const row = cell.getRow().getData();
            const isRendemenRow = String(row.uraian ?? '').toLowerCase().includes('rendemen');

            return formatNumber(cell.getValue(), field, isPercent(field) || isRendemenRow);
        },
        cellClick(event, cell) {
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
        },
    };
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

function pivotLm13Rows(rows) {
    const grouped = new Map();

    for (const row of rows) {
        const key = row.urutan;
        const block = row.block;
        const target = grouped.get(key) ?? {
            urutan: row.urutan,
            kode: row.kode,
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

    return [...grouped.values()].sort((left, right) => left.urutan - right.urutan);
}

function lm13Columns(meta) {
    return [
        ...baseColumns(),
        ...lm13Blocks.map(([block, title]) => ({
            title,
            columns: [
                {
                    title: monthLabel(meta),
                    columns: [
                        numberColumn(`${block}_real_thn_lalu`, 'Real Thn Lalu'),
                        numberColumn(`${block}_bi_jumlah`, 'Real Bln Ini'),
                        numberColumn(`${block}_bi_rko`, 'RKO'),
                        numberColumn(`${block}_bi_rkap`, 'RKAP'),
                    ],
                },
                {
                    title: `s.d ${monthLabel(meta)}`,
                    columns: [
                        numberColumn(`${block}_sd_real_thn_lalu`, 'Real Thn Lalu'),
                        numberColumn(`${block}_sd_jumlah`, 'Real s.d'),
                        numberColumn(`${block}_sd_rko`, 'RKO'),
                        numberColumn(`${block}_sd_rkap`, 'RKAP'),
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
                numberColumn('cap_bi_lalu', 'BI/Lalu'),
                numberColumn('cap_bi_rkap', 'BI/RKAP'),
                numberColumn('cap_bi_sd', 'BI/s.d'),
                numberColumn('cap_sd_rkap', 's.d/RKAP'),
            ],
        },
        {
            title: 'Harga Pokok',
            columns: [
                numberColumn('rp_kg_tbs', 'Rp/kg TBS'),
                numberColumn('rp_kg_mi', 'Rp/kg M+I'),
            ],
        },
    ];
}

function tableRows(reportType, rows) {
    return reportType === 'LM13' ? pivotLm13Rows(rows) : normalizeRows(rows);
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

// Pengukur lebar teks pakai canvas (akurat dengan font yang dipakai sel/header).
let _measureCanvas = null;
function measureText(text, bold) {
    if (!_measureCanvas) {
        _measureCanvas = document.createElement('canvas');
    }
    const ctx = _measureCanvas.getContext('2d');
    const size = COMPACT ? 8.5 : 12;
    ctx.font = `${bold ? '600' : '400'} ${size}px "IBM Plex Sans", system-ui, -apple-system, sans-serif`;
    return ctx.measureText(String(text ?? '')).width;
}

// Header boleh turun baris antar-kata, jadi kolom cukup memuat KATA terpanjang.
function headerWordWidth(title) {
    return String(title ?? '')
        .split(/\s+/)
        .reduce((max, word) => Math.max(max, measureText(word, true)), 0);
}

// Hitung lebar tiap kolom dari isi sebenarnya (data + header) supaya tidak ada
// uraian terpotong "…" atau angka tersembunyi. Lebar = konten terpanjang + padding.
function autosizeColumns(columns, rows) {
    for (const column of columns) {
        if (column.columns) {
            autosizeColumns(column.columns, rows);
            continue;
        }

        const field = column.field;
        if (!field) {
            continue;
        }

        const isText = field === 'uraian' || field === 'kode';
        const percent = isPercent(field);
        let max = headerWordWidth(column.title);

        for (const row of rows) {
            let text;
            let extra = 0;
            if (isText) {
                text = row[field] ?? '';
                if (field === 'uraian') {
                    extra = Number(row.indent ?? 0) * 14; // indentasi level baris
                }
            } else {
                const isRendemenRow = String(row.uraian ?? '').toLowerCase().includes('rendemen');
                text = formatNumber(row[field], field, percent || isRendemenRow);
            }

            const width = measureText(text, false) + extra;
            if (width > max) {
                max = width;
            }
        }

        const padding = COMPACT ? 14 : 22; // padding kiri+kanan + garis
        const buffer = COMPACT ? 6 : 10;   // cadangan agar tak pernah terpotong
        const minWidth = isText ? (COMPACT ? 56 : 76) : (COMPACT ? 52 : 68);

        column.width = Math.max(Math.ceil(max) + padding + buffer, minWidth);
        delete column.minWidth;
    }
}

function rowFormatter(row) {
    const data = row.getData();
    const element = row.getElement();
    element.classList.toggle('lm-row-header', data.row_type === 'header');
    element.classList.toggle('lm-row-subtotal', data.row_type === 'subtotal');
    element.classList.toggle('lm-row-total', data.row_type === 'total');
}

function renderTable(element, reportType, reportData) {
    const payload = reportData ?? { rows: [], meta: {} };
    const rows = tableRows(reportType, Array.isArray(payload.rows) ? payload.rows : []);
    // Deteksi mode fokus saat render: kolom compact + tinggi penuh layar.
    COMPACT = document.body.classList.contains('lm-focus');
    element.innerHTML = '';

    const columns = tableColumns(reportType, payload.meta ?? {});
    autosizeColumns(columns, rows);

    return new Tabulator(element, {
        data: rows,
        columns,
        height: COMPACT ? focusHeight(element) : '65vh',
        // fitData: hormati lebar kolom hasil perhitungan; tabel menggulir mendatar bila perlu.
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
