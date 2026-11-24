-- GorevTakip — InfinityFree / MySQL Idempotent Production Migration
-- 1) Mevcut 'tasarim' birim kayitlarini guvenle 'aviyonik' olarak gunceller
-- 2) Tarihsel zaman damgalarini sunucu saat diliminden Europe/Istanbul (UTC+3) saatine ceker
-- 3) Tekrar calistirildiginda cift kaydirma yapmaz (sistem_migrasyonlari ile korumali)
--
-- Kullanim: phpMyAdmin uzerinden veritabaninizi secin ve SQL sekmesinden bu dosyayi calistirin.
-- On kosul: Bu betik v3 10-tablo semasini (kullanicilar..hatirla_tokenlari) hedefler; eski pre-v3 semalarda once guncel v3 semasini veya config/db.php otomatik migrasyonunu calistirin.
-- Not: CREATE PROCEDURE veya SUPER yetkisi gerektirmez.

CREATE TABLE IF NOT EXISTS sistem_migrasyonlari (
  ad VARCHAR(80) PRIMARY KEY,
  uygulandi_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hatirla_tokenlari (
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

-- 1) Birim migrasyonu: tasarim -> aviyonik (idempotent, guncelleme_tarihi bozulmaz)
SET @birim_uygula = IF(
  EXISTS(SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'birim_aviyonik_v1'),
  0,
  1
);

START TRANSACTION;

UPDATE gorevler
SET birim = 'aviyonik',
    guncelleme_tarihi = guncelleme_tarihi
WHERE @birim_uygula = 1 AND LOWER(TRIM(birim)) IN ('tasarim', 'tasarım');

INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi)
VALUES ('birim_aviyonik_v1', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR));

COMMIT;

-- 2) Zaman dilimi migrasyonu: SET time_zone='+03:00' ONCESI sunucu farkini dinamik hesapla
SET @kayma_sn = 10800 - TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW());
SET @tz_uygula = IF(
  EXISTS(SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'tz_istanbul_v1'),
  0,
  1
);

START TRANSACTION;

UPDATE kullanicilar
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE projeler
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE projeler
SET duyuru_tarihi = DATE_ADD(duyuru_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND duyuru_tarihi IS NOT NULL;

UPDATE proje_uyeleri
SET katilim_tarihi = DATE_ADD(katilim_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND katilim_tarihi IS NOT NULL;

UPDATE gorevler
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND),
    guncelleme_tarihi = DATE_ADD(guncelleme_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0;

UPDATE alt_gorevler
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE yorumlar
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE gorev_dosyalari
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE bildirimler
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE aktiviteler
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

UPDATE hatirla_tokenlari
SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL @kayma_sn SECOND),
    son_gecerlilik = DATE_ADD(son_gecerlilik, INTERVAL @kayma_sn SECOND)
WHERE @tz_uygula = 1 AND @kayma_sn <> 0 AND olusturma_tarihi IS NOT NULL;

INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi)
VALUES ('tz_istanbul_v1', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR));

COMMIT;

-- Bu oturum icin sayisal +03:00 saat dilimini etkinlestir
SET time_zone = '+03:00';
