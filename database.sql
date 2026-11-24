-- GorevTakip - Proje ve Gorev Dagilim Sistemi (v3 Canli Kurulum)
-- MySQL 8 / MariaDB Veritabani Semasi (9 Iliskili Tablo)

-- Yerel MySQL'de yeni veritabani olusturmak isterseniz once olusturup secin:
-- CREATE DATABASE IF NOT EXISTS gorev_takip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE gorev_takip;
-- (InfinityFree / paylasimli hosting phpMyAdmin icinde once veritabanini secip dogrudan bu dosyayi ice aktarin.)

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sistem_migrasyonlari;
DROP TABLE IF EXISTS hatirla_tokenlari;
DROP TABLE IF EXISTS aktiviteler;
DROP TABLE IF EXISTS bildirimler;
DROP TABLE IF EXISTS gorev_dosyalari;
DROP TABLE IF EXISTS yorumlar;
DROP TABLE IF EXISTS alt_gorevler;
DROP TABLE IF EXISTS gorevler;
DROP TABLE IF EXISTS proje_uyeleri;
DROP TABLE IF EXISTS projeler;
DROP TABLE IF EXISTS kullanicilar;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE kullanicilar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad_soyad VARCHAR(120) NOT NULL,
  eposta VARCHAR(150) NOT NULL UNIQUE,
  sifre_hash VARCHAR(255) NOT NULL,
  rol ENUM('yonetici', 'uye') NOT NULL DEFAULT 'uye',
  unvan VARCHAR(100) NOT NULL DEFAULT 'Takım Üyesi',
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projeler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad VARCHAR(160) NOT NULL,
  kod VARCHAR(24) NOT NULL UNIQUE,
  davet_kodu VARCHAR(24) NOT NULL UNIQUE,
  aciklama TEXT NULL,
  duyuru TEXT NULL,
  duyuru_tarihi DATETIME NULL,
  yonetici_id INT UNSIGNED NOT NULL,
  durum ENUM('aktif', 'tamamlandi', 'arsiv') NOT NULL DEFAULT 'aktif',
  baslangic_tarihi DATE NULL,
  bitis_tarihi DATE NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_projeler_yonetici
    FOREIGN KEY (yonetici_id) REFERENCES kullanicilar(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proje_uyeleri (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proje_id INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NOT NULL,
  proje_rolu VARCHAR(80) NOT NULL DEFAULT 'Ekip Üyesi',
  yetki VARCHAR(20) NOT NULL DEFAULT 'uye',
  katilim_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_proje_kullanici (proje_id, kullanici_id),
  INDEX idx_proje_uyeleri_yetki (proje_id, yetki),
  CONSTRAINT fk_proje_uyeleri_proje
    FOREIGN KEY (proje_id) REFERENCES projeler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_proje_uyeleri_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gorevler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proje_id INT UNSIGNED NOT NULL,
  baslik VARCHAR(180) NOT NULL,
  aciklama TEXT NULL,
  atanan_id INT UNSIGNED NULL,
  olusturan_id INT UNSIGNED NOT NULL,
  durum ENUM('yapilacak', 'devam_ediyor', 'tamamlandi') NOT NULL DEFAULT 'yapilacak',
  oncelik ENUM('dusuk', 'orta', 'yuksek') NOT NULL DEFAULT 'orta',
  birim VARCHAR(40) NOT NULL DEFAULT 'genel',
  son_tarih DATE NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  guncelleme_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_gorevler_proje_durum (proje_id, durum),
  INDEX idx_gorevler_proje_birim (proje_id, birim),
  INDEX idx_gorevler_atanan (atanan_id),
  INDEX idx_gorevler_son_tarih (son_tarih),
  CONSTRAINT fk_gorevler_proje
    FOREIGN KEY (proje_id) REFERENCES projeler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_gorevler_atanan
    FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_gorevler_olusturan
    FOREIGN KEY (olusturan_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alt_gorevler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gorev_id INT UNSIGNED NOT NULL,
  hafta_etiketi VARCHAR(60) NOT NULL DEFAULT 'Hafta 1',
  baslik VARCHAR(220) NOT NULL,
  atanan_id INT UNSIGNED NULL,
  son_tarih DATE NULL,
  tamamlandi TINYINT(1) NOT NULL DEFAULT 0,
  sira INT NOT NULL DEFAULT 0,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alt_gorevler_gorev (gorev_id, sira),
  INDEX idx_alt_gorevler_atanan (atanan_id),
  CONSTRAINT fk_alt_gorevler_gorev
    FOREIGN KEY (gorev_id) REFERENCES gorevler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_alt_gorevler_atanan
    FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE yorumlar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gorev_id INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NOT NULL,
  icerik TEXT NOT NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_yorumlar_gorev (gorev_id),
  CONSTRAINT fk_yorumlar_gorev
    FOREIGN KEY (gorev_id) REFERENCES gorevler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_yorumlar_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gorev_dosyalari (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gorev_id INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NOT NULL,
  tur ENUM('dosya', 'link') NOT NULL DEFAULT 'dosya',
  baslik VARCHAR(200) NOT NULL,
  dosya_yolu VARCHAR(255) NULL,
  harici_url VARCHAR(500) NULL,
  dosya_boyutu INT UNSIGNED NOT NULL DEFAULT 0,
  mime_tipi VARCHAR(100) NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gorev_dosyalari_gorev (gorev_id),
  CONSTRAINT fk_gorev_dosyalari_gorev
    FOREIGN KEY (gorev_id) REFERENCES gorevler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_gorev_dosyalari_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bildirimler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici_id INT UNSIGNED NOT NULL,
  tur VARCHAR(40) NOT NULL,
  mesaj VARCHAR(255) NOT NULL,
  link VARCHAR(255) NULL,
  okundu TINYINT(1) NOT NULL DEFAULT 0,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bildirimler_kullanici (kullanici_id, okundu, olusturma_tarihi),
  CONSTRAINT fk_bildirimler_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE aktiviteler (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proje_id INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NULL,
  mesaj VARCHAR(255) NOT NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aktiviteler_proje (proje_id, olusturma_tarihi),
  CONSTRAINT fk_aktiviteler_proje
    FOREIGN KEY (proje_id) REFERENCES projeler(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_aktiviteler_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hatirla_tokenlari (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici_id INT UNSIGNED NOT NULL,
  secici VARCHAR(32) NOT NULL UNIQUE,
  dogrulayici_hash CHAR(64) NOT NULL,
  son_gecerlilik DATETIME NOT NULL,
  olusturma_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hatirla_kullanici (kullanici_id),
  INDEX idx_hatirla_gecerlilik (son_gecerlilik),
  CONSTRAINT fk_hatirla_kullanici
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sistem_migrasyonlari (
  ad VARCHAR(80) PRIMARY KEY,
  uygulandi_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET time_zone = '+03:00';

INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES
  ('tz_istanbul_v1', NOW()),
  ('birim_aviyonik_v1', NOW());
