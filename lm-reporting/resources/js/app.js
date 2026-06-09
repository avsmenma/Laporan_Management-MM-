import './bootstrap';

import Alpine from 'alpinejs';
import { TabulatorFull as Tabulator } from 'tabulator-tables';
import 'tabulator-tables/dist/css/tabulator_semanticui.min.css';

window.Alpine = Alpine;
window.Tabulator = Tabulator;

document.addEventListener('alpine:init', () => {
    Alpine.data('lmDemoTable', () => ({
        table: null,
        rows: [
            { kode: '99-01', uraian: 'Gaji/Upah dan Biaya Kary. Staf', real_bi: 142500000, rkap: 150000000, status: 'Viewer' },
            { kode: '41-01', uraian: 'TM - Pemel Jalan Manual - Access Road', real_bi: 18200000, rkap: 22500000, status: 'Operator' },
            { kode: 'TOTAL', uraian: 'TOTAL BIAYA TANAMAN', real_bi: 160700000, rkap: 172500000, status: 'Admin' },
        ],
        initTable() {
            this.table = new Tabulator(this.$refs.table, {
                data: this.rows,
                height: 280,
                layout: 'fitColumns',
                columns: [
                    { title: 'Kode', field: 'kode', frozen: true, width: 110 },
                    { title: 'Uraian', field: 'uraian', frozen: true, widthGrow: 2 },
                    {
                        title: 'Bulan Mei',
                        columns: [
                            { title: 'Real Bln Ini', field: 'real_bi', hozAlign: 'right', formatter: 'money', formatterParams: { precision: 0, thousand: '.', decimal: ',' } },
                            { title: 'RKAP', field: 'rkap', hozAlign: 'right', formatter: 'money', formatterParams: { precision: 0, thousand: '.', decimal: ',' } },
                        ],
                    },
                    { title: 'Role Demo', field: 'status' },
                ],
            });
        },
    }));
});

Alpine.start();
