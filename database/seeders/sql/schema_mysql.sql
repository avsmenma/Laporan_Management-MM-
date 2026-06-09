-- =====================================================================
-- Sistem Pelaporan LM PTPN IV Regional V — Skema MySQL 8
-- Pasangan dari PRD_Sistem_Pelaporan_LM.md
-- Jalankan urut. Engine InnoDB, utf8mb4.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================== MASTER / REFERENSI ======================
CREATE TABLE ref_unit (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(8) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  type          ENUM('KEBUN','PABRIK') NOT NULL,
  komoditi      ENUM('KS','KR') NULL,
  profit_center VARCHAR(20) NULL,
  olah_status   ENUM('Olah','Non Olah') NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_unit_code (code, komoditi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ref_unit_komoditi (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id   BIGINT UNSIGNED NOT NULL,
  komoditi  ENUM('KS','KR') NOT NULL,
  UNIQUE KEY uq_uk (unit_id, komoditi),
  CONSTRAINT fk_uk_unit FOREIGN KEY (unit_id) REFERENCES ref_unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ref_klasifikasi (
  code  VARCHAR(4) PRIMARY KEY,
  name  VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE batch (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL,
  year          SMALLINT NOT NULL,
  month         TINYINT NOT NULL,
  status        ENUM('draft','final','locked') NOT NULL DEFAULT 'draft',
  processed_at  DATETIME NULL,
  UNIQUE KEY uq_batch (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lm_template_row (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_type  ENUM('LM14','LM13','LM16') NOT NULL,
  komoditi     ENUM('KS','KR') NULL,
  urutan       INT NOT NULL,
  kode         VARCHAR(40) NULL,
  uraian       VARCHAR(200) NOT NULL,
  row_type     ENUM('header','detail','subtotal','total') NOT NULL DEFAULT 'detail',
  source       ENUM('WBS','BTL','PKS','CALC') NULL,
  formula      VARCHAR(255) NULL,
  indent       TINYINT DEFAULT 0,
  KEY idx_tpl (report_type, komoditi, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================== DATA MENTAH =============================
CREATE TABLE db_wbs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id          BIGINT UNSIGNED NOT NULL,
  komoditi          ENUM('KS','KR') NOT NULL,
  plant_code        VARCHAR(8) NOT NULL,
  period            TINYINT NOT NULL,
  aktivitas         VARCHAR(20) NULL,
  job_name          VARCHAR(150) NULL,
  cost_element      VARCHAR(20) NULL,
  cost_element_desc VARCHAR(150) NULL,
  klasifikasi_code  VARCHAR(4) NULL,
  nilai             DECIMAL(20,2) NOT NULL DEFAULT 0,
  fisik             DECIMAL(20,2) NULL,
  KEY idx_wbs (batch_id, komoditi, plant_code, period, aktivitas),
  CONSTRAINT fk_wbs_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE db_btl (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id          BIGINT UNSIGNED NOT NULL,
  komoditi          ENUM('KS','KR') NOT NULL,
  plant_code        VARCHAR(8) NOT NULL,
  unit_kerja        VARCHAR(100) NULL,
  period            TINYINT NOT NULL,
  kode_cc           VARCHAR(20) NULL,
  co_object_name    VARCHAR(150) NULL,
  cost_element      VARCHAR(20) NULL,
  cost_element_name VARCHAR(150) NULL,
  klasifikasi_code  VARCHAR(4) NULL,
  nilai             DECIMAL(20,2) NOT NULL DEFAULT 0,
  KEY idx_btl (batch_id, komoditi, plant_code, period, kode_cc),
  CONSTRAINT fk_btl_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE db_pks (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id     BIGINT UNSIGNED NOT NULL,
  plant_code   VARCHAR(8) NOT NULL,
  period       TINYINT NOT NULL,
  kode_akun    VARCHAR(20) NULL,
  uraian       VARCHAR(150) NULL,
  jenis        ENUM('produksi','biaya') NOT NULL,
  olah_kso     ENUM('Olah','KSO') NULL,
  nilai        DECIMAL(20,2) NOT NULL DEFAULT 0,
  KEY idx_pks (batch_id, plant_code, period, kode_akun),
  CONSTRAINT fk_pks_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== ANGGARAN & PEMBANDING =========================
CREATE TABLE budget_rkap (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year        SMALLINT NOT NULL,
  komoditi    ENUM('KS','KR') NULL,
  plant_code  VARCHAR(8) NOT NULL,
  report_type ENUM('LM14','LM13','LM16') NOT NULL,
  kode        VARCHAR(40) NOT NULL,
  nilai       DECIMAL(20,2) NOT NULL DEFAULT 0,   -- sudah dikali 1000
  KEY idx_rkap (year, komoditi, plant_code, report_type, kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_rko (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year        SMALLINT NOT NULL,
  komoditi    ENUM('KS','KR') NULL,
  plant_code  VARCHAR(8) NOT NULL,
  report_type ENUM('LM14','LM13','LM16') NOT NULL,
  kode        VARCHAR(40) NOT NULL,
  nilai       DECIMAL(20,2) NOT NULL DEFAULT 0,   -- sudah dikali 1000
  KEY idx_rko (year, komoditi, plant_code, report_type, kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE realisasi_tahun_lalu (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year        SMALLINT NOT NULL,
  komoditi    ENUM('KS','KR') NULL,
  plant_code  VARCHAR(8) NOT NULL,
  report_type ENUM('LM14','LM13','LM16') NOT NULL,
  kode        VARCHAR(40) NOT NULL,
  period      TINYINT NOT NULL,
  nilai       DECIMAL(20,2) NOT NULL DEFAULT 0,
  KEY idx_tl (year, komoditi, plant_code, report_type, kode, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================== HASIL (MATERIALIZED REPORT) ===================
CREATE TABLE report_lm14 (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id           BIGINT UNSIGNED NOT NULL,
  unit_id            BIGINT UNSIGNED NOT NULL,
  komoditi           ENUM('KS','KR') NOT NULL,
  template_id        BIGINT UNSIGNED NOT NULL,
  real_bulan_ini     DECIMAL(20,2) DEFAULT 0,
  real_bulan_lalu    DECIMAL(20,2) DEFAULT 0,
  real_tahun_lalu    DECIMAL(20,2) DEFAULT 0,
  rko                DECIMAL(20,2) DEFAULT 0,
  rkap               DECIMAL(20,2) DEFAULT 0,
  cap_bi_lalu        DECIMAL(10,2) DEFAULT 0,
  cap_bi_thnlalu     DECIMAL(10,2) DEFAULT 0,
  cap_bi_rko         DECIMAL(10,2) DEFAULT 0,
  cap_bi_rkap        DECIMAL(10,2) DEFAULT 0,
  real_sd_bulan_ini  DECIMAL(20,2) DEFAULT 0,
  real_sd_tahunlalu  DECIMAL(20,2) DEFAULT 0,
  rko_sd             DECIMAL(20,2) DEFAULT 0,
  rkap_sd            DECIMAL(20,2) DEFAULT 0,
  cap_sd_thnlalu     DECIMAL(10,2) DEFAULT 0,
  cap_sd_rko         DECIMAL(10,2) DEFAULT 0,
  cap_sd_rkap        DECIMAL(10,2) DEFAULT 0,
  KEY idx_r14 (batch_id, unit_id, komoditi),
  CONSTRAINT fk_r14_batch FOREIGN KEY (batch_id) REFERENCES batch(id),
  CONSTRAINT fk_r14_unit  FOREIGN KEY (unit_id)  REFERENCES ref_unit(id),
  CONSTRAINT fk_r14_tpl   FOREIGN KEY (template_id) REFERENCES lm_template_row(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_lm13 (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id     BIGINT UNSIGNED NOT NULL,
  unit_id      BIGINT UNSIGNED NOT NULL,
  komoditi     ENUM('KS','KR') NOT NULL,
  template_id  BIGINT UNSIGNED NOT NULL,
  blok         ENUM('OLAH_JUAL','OLAH','JUAL') NOT NULL,
  bi_real_thn_lalu DECIMAL(20,2) DEFAULT 0,
  bi_real_thn_ini  DECIMAL(20,2) DEFAULT 0,
  bi_rko_tw        DECIMAL(20,2) DEFAULT 0,
  bi_rkap          DECIMAL(20,2) DEFAULT 0,
  sd_real_thn_lalu DECIMAL(20,2) DEFAULT 0,
  sd_real_thn_ini  DECIMAL(20,2) DEFAULT 0,
  sd_rko_tw        DECIMAL(20,2) DEFAULT 0,
  sd_rkap          DECIMAL(20,2) DEFAULT 0,
  KEY idx_r13 (batch_id, unit_id, komoditi, blok),
  CONSTRAINT fk_r13_batch FOREIGN KEY (batch_id) REFERENCES batch(id),
  CONSTRAINT fk_r13_unit  FOREIGN KEY (unit_id)  REFERENCES ref_unit(id),
  CONSTRAINT fk_r13_tpl   FOREIGN KEY (template_id) REFERENCES lm_template_row(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report_lm16 (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id        BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NOT NULL,
  template_id     BIGINT UNSIGNED NOT NULL,
  real_bln_lalu   DECIMAL(20,2) DEFAULT 0,
  bi_olah         DECIMAL(20,2) DEFAULT 0,
  bi_kso          DECIMAL(20,2) DEFAULT 0,
  bi_jumlah       DECIMAL(20,2) DEFAULT 0,
  bi_rko          DECIMAL(20,2) DEFAULT 0,
  bi_rkap         DECIMAL(20,2) DEFAULT 0,
  sd_olah         DECIMAL(20,2) DEFAULT 0,
  sd_kso          DECIMAL(20,2) DEFAULT 0,
  sd_jumlah       DECIMAL(20,2) DEFAULT 0,
  sd_rko          DECIMAL(20,2) DEFAULT 0,
  sd_rkap         DECIMAL(20,2) DEFAULT 0,
  cap_bi_lalu     DECIMAL(10,2) DEFAULT 0,
  cap_bi_rkap     DECIMAL(10,2) DEFAULT 0,
  cap_bi_sd       DECIMAL(10,2) DEFAULT 0,
  cap_sd_rkap     DECIMAL(10,2) DEFAULT 0,
  rp_kg_tbs       DECIMAL(18,4) DEFAULT 0,
  rp_kg_mi        DECIMAL(18,4) DEFAULT 0,
  KEY idx_r16 (batch_id, unit_id),
  CONSTRAINT fk_r16_batch FOREIGN KEY (batch_id) REFERENCES batch(id),
  CONSTRAINT fk_r16_unit  FOREIGN KEY (unit_id)  REFERENCES ref_unit(id),
  CONSTRAINT fk_r16_tpl   FOREIGN KEY (template_id) REFERENCES lm_template_row(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================== SEED MASTER =============================
INSERT INTO ref_klasifikasi (code,name) VALUES
 ('1','Gaji'),('2','SPK'),('3','Bahan'),('4','EAP'),('5','Depresiasi'),('6','Lain-Lain');

-- Kebun (type KEBUN). komoditi NULL di ref_unit; komoditi nyata via ref_unit_komoditi.
INSERT INTO ref_unit (code,name,type,komoditi,profit_center,olah_status) VALUES
 ('5E01','Kebun Gunung Meliau','KEBUN',NULL,'5E01000001',NULL),
 ('5E02','Kebun Gunung Mas','KEBUN',NULL,'5E02000001',NULL),
 ('5E03','Kebun Sungai Dekan','KEBUN',NULL,'5E03000001',NULL),
 ('5E04','Kebun Rimba Belian','KEBUN',NULL,'5E04000001',NULL),
 ('5E06','Kebun Sintang','KEBUN',NULL,'5E06000001',NULL),
 ('5E07','Kebun Ngabang','KEBUN',NULL,'5E07000001',NULL),
 ('5E08','Kebun Parindu','KEBUN',NULL,'5E08000001',NULL),
 ('5E09','Kebun Kembayan','KEBUN',NULL,'5E09000001',NULL),
 ('5E11','Kebun Danau Salak','KEBUN',NULL,'5E11000001',NULL),
 ('5E12','Kebun Kumai','KEBUN',NULL,'5E12000001',NULL),
 ('5E13','Kebun Batulicin','KEBUN',NULL,'5E13000001',NULL),
 ('5E14','Kebun Pamukan','KEBUN',NULL,'5E14000001',NULL),
 ('5E15','Kebun Pelaihari','KEBUN',NULL,'5E15000001',NULL),
 ('5E16','Kebun Tabara','KEBUN',NULL,'5E16000001',NULL),
 ('5E17','Kebun Tajati','KEBUN',NULL,'5E17000001',NULL),
 ('5E18','Kebun Pandawa','KEBUN',NULL,'5E18000001',NULL),
 ('5E19','Kebun Longkali','KEBUN',NULL,'5E19000001',NULL);

-- Pabrik (type PABRIK). PKS = sawit, PKR = karet.
INSERT INTO ref_unit (code,name,type,komoditi,profit_center,olah_status) VALUES
 ('5F01','PKS Gunung Meliau','PABRIK','KS','5F01000001','Olah'),
 ('5F04','PKS Rimba Belian','PABRIK','KS','5F04000001','Olah'),
 ('5F07','PKS Ngabang','PABRIK','KS','5F07000001','Olah'),
 ('5F08','PKS Parindu','PABRIK','KS','5F08000001','Olah'),
 ('5F09','PKS Kembayan','PABRIK','KS','5F09000001','Olah'),
 ('5F14','PKS Pamukan','PABRIK','KS','5F14000001','Non Olah'),
 ('5F15','PKS Pelaihari','PABRIK','KS','5F15000001','Olah'),
 ('5F20','PKR Tambarangan','PABRIK','KR','5F20000001','Non Olah'),
 ('5F21','PKS Samuntai','PABRIK','KS','5F21000001','Non Olah'),
 ('5F22','PKS Long Pinang','PABRIK','KS','5F22000001','Olah');

-- Catatan: isi ref_unit_komoditi untuk tiap kebun sesuai keberadaan data
-- (mis. semua kebun punya KS; sebagian punya KR). Contoh:
-- INSERT INTO ref_unit_komoditi (unit_id,komoditi)
--   SELECT id,'KS' FROM ref_unit WHERE type='KEBUN';
-- INSERT INTO ref_unit_komoditi (unit_id,komoditi)
--   SELECT id,'KR' FROM ref_unit WHERE code IN ('5E06','5E12','5E13','5E19');

-- =====================================================================
-- ADDENDUM: tabel sumber LM13 (Alokasi) & LM16 (Summary/LM625F01)
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- Alokasi produksi kebun -> pabrik (asal sheet "Alokasi", kolom N=produk, L=jumlah)
CREATE TABLE alokasi_produksi (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id    BIGINT UNSIGNED NOT NULL,
  year        SMALLINT NOT NULL,
  month       TINYINT NOT NULL,
  kebun_code  VARCHAR(8) NOT NULL,          -- kolom B
  pabrik_code VARCHAR(8) NULL,              -- kolom C..K (NULL bila pakai Jumlah/L saja)
  produk      VARCHAR(40) NOT NULL,         -- 'Stok Awal TBS','TBS Diterima','TBS Olah',
                                            -- 'TBS Restan Loading Ramp','CPO','Kernel','CPO + Kernel',...
  jumlah      DECIMAL(20,2) NOT NULL DEFAULT 0,  -- kolom L (atau nilai pabrik tertentu)
  KEY idx_alok (batch_id, kebun_code, produk, month),
  CONSTRAINT fk_alok_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Luas areal TM per kebun (asal sheet "Alokasi" blok III. Areal F236:J252)
CREATE TABLE alokasi_areal (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year           SMALLINT NOT NULL,
  kebun_code     VARCHAR(8) NOT NULL,
  real_thn_lalu  DECIMAL(14,2) DEFAULT 0,
  real_thn_ini   DECIMAL(14,2) DEFAULT 0,
  rko            DECIMAL(14,2) DEFAULT 0,
  rkap           DECIMAL(14,2) DEFAULT 0,
  UNIQUE KEY uq_areal (year, kebun_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Biaya pabrik mentah (asal sheet "Summary"): analog DB WBS/BTL untuk pabrik
CREATE TABLE pks_biaya (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id      BIGINT UNSIGNED NOT NULL,
  plant_code    VARCHAR(8) NOT NULL,
  period        TINYINT NOT NULL,
  cost_center   VARCHAR(20) NULL,           -- Summary kolom D (BT01.. untuk overhead)
  cost_element  VARCHAR(20) NULL,           -- Summary kolom F (GL untuk pengolahan)
  klasifikasi_code VARCHAR(4) NULL,
  nilai         DECIMAL(20,2) NOT NULL DEFAULT 0,  -- Summary kolom I
  KEY idx_pksb (batch_id, plant_code, period, cost_center, cost_element),
  CONSTRAINT fk_pksb_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Produksi pabrik mentah (asal sheet "LM625F01"): form key-value
CREATE TABLE pks_produksi (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id    BIGINT UNSIGNED NOT NULL,
  plant_code  VARCHAR(8) NOT NULL,
  period      TINYINT NOT NULL,
  uraian      VARCHAR(80) NOT NULL,         -- LM625F01 kolom B (kunci lookup)
  nilai_bi    DECIMAL(20,2) DEFAULT 0,      -- kolom E (Bulan Ini)
  nilai_sd    DECIMAL(20,2) DEFAULT 0,      -- kolom F (s.d Bulan Ini)
  KEY idx_pksp (batch_id, plant_code, period, uraian),
  CONSTRAINT fk_pksp_batch FOREIGN KEY (batch_id) REFERENCES batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
-- Catatan: tabel db_pks (skema awal) boleh digantikan oleh pks_biaya + pks_produksi.
