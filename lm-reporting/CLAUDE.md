# Aturan Kerja Proyek LM

- Sumber kebenaran: `docs/PRD_Sistem_Pelaporan_LM.md`, `docs/PROMPTS_AI_Agent_LM.md`, SQL seed di `database/seeders/sql`, dan workbook acuan Mei 2026.
- Stack: Laravel 12, PHP 8.3+, MySQL 8, Blade + Alpine.js + Tabulator.js.
- Kerjakan satu prompt saja sesuai instruksi user. Jangan lanjut tahap berikutnya sebelum acceptance tahap sekarang lolos dan user menandai lanjut.
- Prinsip utama: bentuk, susunan kolom, grouped header, frozen identity column, urutan baris, dan blok kolom laporan harus identik dengan Excel acuan.
- Commit kecil dan sering, pesan commit Bahasa Indonesia.
- Gunakan `git add` per-file, hindari `git add .`.
- Jangan mengubah data/server produksi.
- Jangan menjalankan perintah destruktif tanpa konfirmasi eksplisit.
- RKO/RKAP disimpan dalam nilai penuh, bukan ribuan.
- Multi-periode wajib; kumulatif dihitung dari data, bukan IMPORTRANGE.
- Viewer hanya boleh melihat report final atau locked.
