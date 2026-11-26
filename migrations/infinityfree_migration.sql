-- GorevTakip — InfinityFree / MySQL Idempotent Production Migration
-- 1) Mevcut 'tasarim' birim kayitlarini guvenle 'aviyonik' olarak gunceller
-- 2) Tarihsel zaman damgalarini sunucu saat diliminden Europe/Istanbul (UTC+3) saatine ceker
-- 3) gorevler.birim sutununu 'aerodinamik' destegi icin VARCHAR(40) olarak guvenceye alir ve varyantlari normalize eder (birim_aerodinamik_v1)
-- 4) IHA ekip semasina gore uye unvanlarini (Ramazan Faruk Acioz ve Nursena Kilavuz haric) gunceller (ekip_unvanlari_v1)
-- 5) Tekrar calistirildiginda cift islem yapmaz (sistem_migrasyonlari ile korumali)
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

-- 3) Aerodinamik birim destegi ve varyant normalizasyonu (birim_aerodinamik_v1)
-- Eski ENUM veya dar VARCHAR kurulumlarinda gorevler.birim sutununu guvenle genisletir (DDL implicit commit nedeniyle transaction disinda)
ALTER TABLE gorevler
  MODIFY COLUMN birim VARCHAR(40) NOT NULL DEFAULT 'genel';

SET @birim_aero_uygula = IF(
  EXISTS(SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'birim_aerodinamik_v1'),
  0,
  1
);

START TRANSACTION;

UPDATE gorevler
SET birim = 'aerodinamik',
    guncelleme_tarihi = guncelleme_tarihi
WHERE @birim_aero_uygula = 1
  AND BINARY birim <> 'aerodinamik'
  AND (
    LOWER(TRIM(birim)) IN ('aerodinamik', 'aerodınamık', 'aerodınamik', 'aerodinamık')
    OR TRIM(birim) IN (
      'Aerodinamik',
      'AERODİNAMİK',
      'AERODINAMIK',
      'Aerodınamık',
      'aerodİnamİk',
      'aerodİnamik',
      'aerodinamİk'
    )
  );

INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi)
VALUES ('birim_aerodinamik_v1', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR));

COMMIT;

-- 4) Ekip unvanlari migrasyonu (ekip_unvanlari_v1)
-- Ramazan Faruk Acıöz (Takım Lideri) ve Nursena KILAVUZ (İletişim Sorumlusu) KESİNLİKLE DOKUNULMADAN BIRAKILIR.
SET @unvan_uygula = IF(
  EXISTS(SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'ekip_unvanlari_v1')
  OR NOT EXISTS(SELECT 1 FROM kullanicilar LIMIT 1),
  0,
  1
);

START TRANSACTION;

UPDATE kullanicilar
SET unvan = 'Aerodinamik Ekibi Üyesi'
WHERE @unvan_uygula = 1
  AND REPLACE(TRIM(ad_soyad), '  ', ' ') IN (
    'Emine Gür', 'emine gür', 'EMİNE GÜR', 'EMINE GÜR', 'Emine Gur', 'EMINE GUR',
    'Türker Türk', 'türker türk', 'TÜRKER TÜRK', 'Turker Turk', 'TURKER TURK'
  );

UPDATE kullanicilar
SET unvan = 'Yazılım Ekibi Üyesi'
WHERE @unvan_uygula = 1
  AND REPLACE(TRIM(ad_soyad), '  ', ' ') IN (
    'Emirhan Erdoğan', 'emirhan erdoğan', 'EMİRHAN ERDOĞAN', 'EMIRHAN ERDOĞAN', 'Emirhan Erdogan', 'EMIRHAN ERDOGAN',
    'Fatma Sağır', 'fatma sağır', 'FATMA SAĞIR', 'Fatma Sagir', 'FATMA SAGIR', 'FATMA SAĞİR',
    'Ömer Şahin', 'ömer şahin', 'ÖMER ŞAHİN', 'ÖMER ŞAHIN', 'Omer Sahin', 'OMER SAHIN'
  );

UPDATE kullanicilar
SET unvan = 'Aviyonik Ekibi Lideri'
WHERE @unvan_uygula = 1
  AND REPLACE(TRIM(ad_soyad), '  ', ' ') IN (
    'Eray Kilci', 'eray kilci', 'ERAY KİLCİ', 'ERAY KILCI', 'Eray Kılcı'
  );

UPDATE kullanicilar
SET unvan = 'Mekanik Ekibi Üyesi'
WHERE @unvan_uygula = 1
  AND REPLACE(TRIM(ad_soyad), '  ', ' ') IN (
    'Evren Yeşilyaprak', 'Evren yeşilyaprak', 'evren yeşilyaprak', 'EVREN YEŞİLYAPRAK', 'EVREN YEŞILYAPRAK', 'Evren Yesilyaprak', 'EVREN YESILYAPRAK'
  );

UPDATE kullanicilar
SET unvan = 'Aviyonik Ekibi Üyesi'
WHERE @unvan_uygula = 1
  AND REPLACE(TRIM(ad_soyad), '  ', ' ') IN (
    'Kayra Şener Afşar', 'kayra şener afşar', 'KAYRA ŞENER AFŞAR', 'Kayra Sener Afsar', 'KAYRA SENER AFSAR'
  );

UPDATE proje_uyeleri pu
INNER JOIN kullanicilar u ON u.id = pu.kullanici_id
SET pu.proje_rolu = u.unvan
WHERE @unvan_uygula = 1
  AND (pu.proje_rolu IS NULL OR TRIM(pu.proje_rolu) = '' OR TRIM(pu.proje_rolu) = 'Ekip Üyesi')
  AND REPLACE(TRIM(u.ad_soyad), '  ', ' ') IN (
    'Emine Gür', 'emine gür', 'EMİNE GÜR', 'EMINE GÜR', 'Emine Gur', 'EMINE GUR',
    'Türker Türk', 'türker türk', 'TÜRKER TÜRK', 'Turker Turk', 'TURKER TURK',
    'Emirhan Erdoğan', 'emirhan erdoğan', 'EMİRHAN ERDOĞAN', 'EMIRHAN ERDOĞAN', 'Emirhan Erdogan', 'EMIRHAN ERDOGAN',
    'Fatma Sağır', 'fatma sağır', 'FATMA SAĞIR', 'Fatma Sagir', 'FATMA SAGIR', 'FATMA SAĞİR',
    'Ömer Şahin', 'ömer şahin', 'ÖMER ŞAHİN', 'ÖMER ŞAHIN', 'Omer Sahin', 'OMER SAHIN',
    'Eray Kilci', 'eray kilci', 'ERAY KİLCİ', 'ERAY KILCI', 'Eray Kılcı',
    'Evren Yeşilyaprak', 'Evren yeşilyaprak', 'evren yeşilyaprak', 'EVREN YEŞİLYAPRAK', 'EVREN YEŞILYAPRAK', 'Evren Yesilyaprak', 'EVREN YESILYAPRAK',
    'Kayra Şener Afşar', 'kayra şener afşar', 'KAYRA ŞENER AFŞAR', 'Kayra Sener Afsar', 'KAYRA SENER AFSAR'
  )
  AND REPLACE(TRIM(u.ad_soyad), '  ', ' ') NOT IN (
    'Ramazan Faruk Acıöz', 'RAMAZAN FARUK ACIÖZ', 'Ramazan Faruk Acioz',
    'Nursena Kılavuz', 'Nursena KILAVUZ', 'NURSENA KILAVUZ', 'Nursena Kilavuz'
  );

INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi)
SELECT 'ekip_unvanlari_v1', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)
FROM kullanicilar
WHERE @unvan_uygula = 1
LIMIT 1;

COMMIT;

-- Bu oturum icin sayisal +03:00 saat dilimini etkinlestir
SET time_zone = '+03:00';
