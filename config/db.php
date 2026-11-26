<?php
/**
 * Veritabani baglanti yapilandirmasi.
 * Varsayilan olarak MySQL (PDO) kullanir.
 * Yerel test icin APP_DB_DRIVER=sqlite ortam degiskeni ile SQLite destegi sunar.
 */

require_once __DIR__ . '/app.php';

if (!function_exists('db_istanbul_simdi')) {
    function db_istanbul_simdi()
    {
        try {
            $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
            return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return date('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('db_sqlite_tablo_var_mi')) {
    function db_sqlite_tablo_var_mi(PDO $pdo, $tablo_adi)
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $stmt->execute(array($tablo_adi));
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('db_turkce_kucuk_harf')) {
    function db_turkce_kucuk_harf($deger)
    {
        $metin = trim((string) $deger);
        if ($metin === '') {
            return '';
        }
        $daraltilmis = @preg_replace('/\s+/u', ' ', $metin);
        $metin = ($daraltilmis !== null) ? $daraltilmis : preg_replace('/\s+/', ' ', $metin);

        // NFD birlesik nokta/aksan dizilerini NFC kucuk harf karsiliklarina indirge
        $nfd_harita = array(
            "i\xCC\x87" => 'i',
            "I\xCC\x87" => 'i',
            "u\xCC\x88" => 'ü',
            "U\xCC\x88" => 'ü',
            "o\xCC\x88" => 'ö',
            "O\xCC\x88" => 'ö',
            "s\xCC\xA7" => 'ş',
            "S\xCC\xA7" => 'ş',
            "c\xCC\xA7" => 'ç',
            "C\xCC\xA7" => 'ç',
            "g\xCC\x86" => 'ğ',
            "G\xCC\x86" => 'ğ',
            "\xCC\x87" => '',
        );
        $metin = strtr($metin, $nfd_harita);

        $harf_haritasi = array(
            'İ' => 'i',
            'I' => 'ı',
            'ı' => 'ı',
            'Ş' => 'ş',
            'Ğ' => 'ğ',
            'Ü' => 'ü',
            'Ö' => 'ö',
            'Ç' => 'ç',
            'A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e', 'F' => 'f',
            'G' => 'g', 'H' => 'h', 'J' => 'j', 'K' => 'k', 'L' => 'l', 'M' => 'm',
            'N' => 'n', 'O' => 'o', 'P' => 'p', 'Q' => 'q', 'R' => 'r', 'S' => 's',
            'T' => 't', 'U' => 'u', 'V' => 'v', 'W' => 'w', 'X' => 'x', 'Y' => 'y',
            'Z' => 'z',
        );

        return strtr($metin, $harf_haritasi);
    }
}

if (!function_exists('db_ascii_katla')) {
    function db_ascii_katla($deger)
    {
        $kucuk = db_turkce_kucuk_harf($deger);
        if ($kucuk === '') {
            return '';
        }
        return strtr($kucuk, array(
            'ı' => 'i',
            'ş' => 's',
            'ğ' => 'g',
            'ü' => 'u',
            'ö' => 'o',
            'ç' => 'c',
        ));
    }
}

if (!function_exists('db_birim_aerodinamik_migrasyonu_calistir')) {
    function db_birim_aerodinamik_migrasyonu_calistir(PDO $pdo, $surucu)
    {
        $kontrol = $pdo->query("SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'birim_aerodinamik_v1' LIMIT 1");
        if ($kontrol && $kontrol->fetchColumn()) {
            return;
        }

        $gorevler_var = ($surucu === 'sqlite')
            ? db_sqlite_tablo_var_mi($pdo, 'gorevler')
            : db_mysql_tablo_var_mi($pdo, 'gorevler');

        if ($gorevler_var) {
            if ($surucu === 'mysql') {
                $sutun_stmt = $pdo->query("SHOW COLUMNS FROM gorevler LIKE 'birim'");
                $sutun = $sutun_stmt ? $sutun_stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($sutun && isset($sutun['Type'])) {
                    $tip = strtolower(trim((string) $sutun['Type']));
                    $genislet = false;
                    if (strpos($tip, 'enum(') === 0) {
                        $genislet = true;
                    } elseif (preg_match('/^varchar\((\d+)\)$/', $tip, $eslesme) && (int) $eslesme[1] < 40) {
                        $genislet = true;
                    }
                    if ($genislet) {
                        $pdo->exec("ALTER TABLE gorevler MODIFY COLUMN birim VARCHAR(40) NOT NULL DEFAULT 'genel'");
                    }
                }

                $aday_stmt = $pdo->query("SELECT id, birim FROM gorevler WHERE birim IS NOT NULL AND BINARY birim <> 'aerodinamik'");
                $adaylar = $aday_stmt ? $aday_stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                if (!empty($adaylar)) {
                    $guncelle = $pdo->prepare("UPDATE gorevler SET birim = 'aerodinamik', guncelleme_tarihi = guncelleme_tarihi WHERE id = ?");
                    foreach ($adaylar as $aday) {
                        $ham = isset($aday['birim']) ? (string) $aday['birim'] : '';
                        if ($ham !== 'aerodinamik' && db_ascii_katla($ham) === 'aerodinamik') {
                            $guncelle->execute(array((int) $aday['id']));
                        }
                    }
                }
            } else {
                $aday_stmt = $pdo->query("SELECT id, birim FROM gorevler WHERE birim IS NOT NULL AND birim <> 'aerodinamik'");
                $adaylar = $aday_stmt ? $aday_stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                if (!empty($adaylar)) {
                    $guncelle = $pdo->prepare("UPDATE gorevler SET birim = 'aerodinamik' WHERE id = ?");
                    foreach ($adaylar as $aday) {
                        $ham = isset($aday['birim']) ? (string) $aday['birim'] : '';
                        if ($ham !== 'aerodinamik' && db_ascii_katla($ham) === 'aerodinamik') {
                            $guncelle->execute(array((int) $aday['id']));
                        }
                    }
                }
            }
        }

        $kayit_sql = ($surucu === 'sqlite')
            ? 'INSERT OR IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)'
            : 'INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)';
        $kayit_stmt = $pdo->prepare($kayit_sql);
        $kayit_stmt->execute(array('birim_aerodinamik_v1', db_istanbul_simdi()));
    }
}

if (!function_exists('db_ekip_unvan_migrasyonu_calistir')) {
    function db_ekip_unvan_migrasyonu_calistir(PDO $pdo, $surucu)
    {
        $kontrol = $pdo->query("SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'ekip_unvanlari_v1' LIMIT 1");
        if ($kontrol && $kontrol->fetchColumn()) {
            return;
        }

        $kullanici_stmt = $pdo->query('SELECT id, ad_soyad, unvan FROM kullanicilar');
        $kullanicilar = $kullanici_stmt ? $kullanici_stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        if (empty($kullanicilar)) {
            return;
        }

        // Ramazan Faruk Acıöz (Takım Lideri) ve Nursena KILAVUZ (İletişim Sorumlusu) kesinlikle haric tutulur.
        $haric_tr = array(
            'ramazan faruk acıöz' => true,
            'nursena kılavuz' => true,
        );
        $haric_ascii = array(
            'ramazan faruk acioz' => true,
            'nursena kilavuz' => true,
        );

        $unvan_haritasi_tr = array(
            'emine gür' => 'Aerodinamik Ekibi Üyesi',
            'emirhan erdoğan' => 'Yazılım Ekibi Üyesi',
            'eray kilci' => 'Aviyonik Ekibi Lideri',
            'evren yeşilyaprak' => 'Mekanik Ekibi Üyesi',
            'fatma sağır' => 'Yazılım Ekibi Üyesi',
            'kayra şener afşar' => 'Aviyonik Ekibi Üyesi',
            'ömer şahin' => 'Yazılım Ekibi Üyesi',
            'türker türk' => 'Aerodinamik Ekibi Üyesi',
        );

        $unvan_haritasi_ascii = array(
            'emine gur' => 'Aerodinamik Ekibi Üyesi',
            'emirhan erdogan' => 'Yazılım Ekibi Üyesi',
            'eray kilci' => 'Aviyonik Ekibi Lideri',
            'evren yesilyaprak' => 'Mekanik Ekibi Üyesi',
            'fatma sagir' => 'Yazılım Ekibi Üyesi',
            'kayra sener afsar' => 'Aviyonik Ekibi Üyesi',
            'omer sahin' => 'Yazılım Ekibi Üyesi',
            'turker turk' => 'Aerodinamik Ekibi Üyesi',
        );

        $proje_uyeleri_var = ($surucu === 'sqlite')
            ? db_sqlite_tablo_var_mi($pdo, 'proje_uyeleri')
            : db_mysql_tablo_var_mi($pdo, 'proje_uyeleri');

        $pdo->beginTransaction();
        try {
            $unvan_stmt = $pdo->prepare('UPDATE kullanicilar SET unvan = ? WHERE id = ?');
            $proje_rol_stmt = $proje_uyeleri_var
                ? $pdo->prepare("UPDATE proje_uyeleri SET proje_rolu = ? WHERE kullanici_id = ? AND (proje_rolu IS NULL OR TRIM(proje_rolu) = '' OR TRIM(proje_rolu) = 'Ekip Üyesi')")
                : null;

            foreach ($kullanicilar as $satir) {
                $ham_ad = isset($satir['ad_soyad']) ? (string) $satir['ad_soyad'] : '';
                $ad_tr = db_turkce_kucuk_harf($ham_ad);
                $ad_ascii = db_ascii_katla($ham_ad);

                if ($ad_tr === '' || isset($haric_tr[$ad_tr]) || isset($haric_ascii[$ad_ascii])) {
                    continue;
                }

                $yeni_unvan = null;
                if (isset($unvan_haritasi_tr[$ad_tr])) {
                    $yeni_unvan = $unvan_haritasi_tr[$ad_tr];
                } elseif (isset($unvan_haritasi_ascii[$ad_ascii])) {
                    $yeni_unvan = $unvan_haritasi_ascii[$ad_ascii];
                }

                if ($yeni_unvan !== null) {
                    $kullanici_id = (int) $satir['id'];
                    $unvan_stmt->execute(array($yeni_unvan, $kullanici_id));
                    if ($proje_rol_stmt) {
                        $proje_rol_stmt->execute(array($yeni_unvan, $kullanici_id));
                    }
                }
            }

            $kayit_sql = ($surucu === 'sqlite')
                ? 'INSERT OR IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)'
                : 'INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)';
            $kayit_stmt = $pdo->prepare($kayit_sql);
            $kayit_stmt->execute(array('ekip_unvanlari_v1', db_istanbul_simdi()));

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('db_sqlite_baslat')) {
    function db_sqlite_baslat(PDO $pdo)
    {
        $pdo->exec('PRAGMA foreign_keys = ON;');

        if (db_sqlite_tablo_var_mi($pdo, 'kullanicilar')) {
            // Mevcut SQLite veritabani varsa yeni v3 tablo/sutunlari guvenle tamamla
            if (!db_sqlite_tablo_var_mi($pdo, 'alt_gorevler')) {
                $pdo->exec("
                    CREATE TABLE alt_gorevler (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        gorev_id INTEGER NOT NULL,
                        hafta_etiketi TEXT NOT NULL DEFAULT 'Hafta 1',
                        baslik TEXT NOT NULL,
                        atanan_id INTEGER NULL,
                        son_tarih TEXT NULL,
                        tamamlandi INTEGER NOT NULL DEFAULT 0,
                        sira INTEGER NOT NULL DEFAULT 0,
                        olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                        FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                        FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
                    );
                ");
            }

            if (!db_sqlite_tablo_var_mi($pdo, 'bildirimler')) {
                $pdo->exec("
                    CREATE TABLE bildirimler (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        kullanici_id INTEGER NOT NULL,
                        tur TEXT NOT NULL,
                        mesaj TEXT NOT NULL,
                        link TEXT NULL,
                        okundu INTEGER NOT NULL DEFAULT 0,
                        olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                        FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
                    );
                ");
            }

            if (!db_sqlite_tablo_var_mi($pdo, 'aktiviteler')) {
                $pdo->exec("
                    CREATE TABLE aktiviteler (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        proje_id INTEGER NOT NULL,
                        kullanici_id INTEGER NULL,
                        mesaj TEXT NOT NULL,
                        olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                        FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                        FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
                    );
                ");
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sistem_migrasyonlari (
                    ad TEXT PRIMARY KEY,
                    uygulandi_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours'))
                );
            ");

            // Idempotent Europe/Istanbul (+3 saat) tarihsel zaman damgasi migrasyonu
            $tz_kontrol = $pdo->query("SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'tz_istanbul_v1' LIMIT 1");
            if (!$tz_kontrol || !$tz_kontrol->fetchColumn()) {
                $pdo->beginTransaction();
                try {
                    $kaydirma_listesi = array(
                        'kullanicilar' => array('olusturma_tarihi'),
                        'projeler' => array('olusturma_tarihi', 'duyuru_tarihi'),
                        'proje_uyeleri' => array('katilim_tarihi'),
                        'gorevler' => array('olusturma_tarihi', 'guncelleme_tarihi'),
                        'alt_gorevler' => array('olusturma_tarihi'),
                        'yorumlar' => array('olusturma_tarihi'),
                        'gorev_dosyalari' => array('olusturma_tarihi'),
                        'bildirimler' => array('olusturma_tarihi'),
                        'aktiviteler' => array('olusturma_tarihi'),
                        'hatirla_tokenlari' => array('olusturma_tarihi', 'son_gecerlilik'),
                    );
                    foreach ($kaydirma_listesi as $tablo => $sutunlar) {
                        if (!db_sqlite_tablo_var_mi($pdo, $tablo)) {
                            continue;
                        }
                        foreach ($sutunlar as $sutun) {
                            $pdo->exec(
                                "UPDATE {$tablo} SET {$sutun} = datetime({$sutun}, '+3 hours') " .
                                "WHERE {$sutun} IS NOT NULL AND TRIM({$sutun}) <> '';"
                            );
                        }
                    }
                    $kayit_stmt = $pdo->prepare('INSERT OR IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)');
                    $kayit_stmt->execute(array('tz_istanbul_v1', db_istanbul_simdi()));
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            // Idempotent legacy 'tasarim' -> 'aviyonik' birim migrasyonu
            $birim_kosul = "LOWER(TRIM(birim)) IN ('tasarim', 'tasarım') OR TRIM(birim) IN ('TASARİM', 'tasari\xCC\x87m', 'TASARI\xCC\x87M')";
            $eski_birim_var = $pdo->query("SELECT 1 FROM gorevler WHERE {$birim_kosul} LIMIT 1");
            if ($eski_birim_var && $eski_birim_var->fetchColumn()) {
                $pdo->exec("UPDATE gorevler SET birim = 'aviyonik' WHERE {$birim_kosul};");
            }
            $birim_stmt = $pdo->prepare('INSERT OR IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)');
            $birim_stmt->execute(array('birim_aviyonik_v1', db_istanbul_simdi()));

            db_birim_aerodinamik_migrasyonu_calistir($pdo, 'sqlite');
            db_ekip_unvan_migrasyonu_calistir($pdo, 'sqlite');

            return;
        }

        $pdo->exec("
            CREATE TABLE kullanicilar (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ad_soyad TEXT NOT NULL,
                eposta TEXT NOT NULL UNIQUE,
                sifre_hash TEXT NOT NULL,
                rol TEXT NOT NULL DEFAULT 'uye' CHECK (rol IN ('yonetici', 'uye')),
                unvan TEXT NOT NULL DEFAULT 'Takım Üyesi',
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours'))
            );

            CREATE TABLE projeler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ad TEXT NOT NULL,
                kod TEXT NOT NULL UNIQUE,
                davet_kodu TEXT NOT NULL UNIQUE,
                aciklama TEXT NULL,
                duyuru TEXT NULL,
                duyuru_tarihi TEXT NULL,
                yonetici_id INTEGER NOT NULL,
                durum TEXT NOT NULL DEFAULT 'aktif' CHECK (durum IN ('aktif', 'tamamlandi', 'arsiv')),
                baslangic_tarihi TEXT NULL,
                bitis_tarihi TEXT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (yonetici_id) REFERENCES kullanicilar(id) ON DELETE RESTRICT ON UPDATE CASCADE
            );

            CREATE TABLE proje_uyeleri (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proje_id INTEGER NOT NULL,
                kullanici_id INTEGER NOT NULL,
                proje_rolu TEXT NOT NULL DEFAULT 'Ekip Üyesi',
                yetki TEXT NOT NULL DEFAULT 'uye',
                katilim_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                UNIQUE (proje_id, kullanici_id),
                FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE gorevler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proje_id INTEGER NOT NULL,
                baslik TEXT NOT NULL,
                aciklama TEXT NULL,
                atanan_id INTEGER NULL,
                olusturan_id INTEGER NOT NULL,
                durum TEXT NOT NULL DEFAULT 'yapilacak' CHECK (durum IN ('yapilacak', 'devam_ediyor', 'tamamlandi')),
                oncelik TEXT NOT NULL DEFAULT 'orta' CHECK (oncelik IN ('dusuk', 'orta', 'yuksek')),
                -- Desteklenen birimler: genel, yazilim, elektronik, mekanik, raporlama, aviyonik, aerodinamik
                birim TEXT NOT NULL DEFAULT 'genel',
                son_tarih TEXT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                guncelleme_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE,
                FOREIGN KEY (olusturan_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE alt_gorevler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gorev_id INTEGER NOT NULL,
                hafta_etiketi TEXT NOT NULL DEFAULT 'Hafta 1',
                baslik TEXT NOT NULL,
                atanan_id INTEGER NULL,
                son_tarih TEXT NULL,
                tamamlandi INTEGER NOT NULL DEFAULT 0,
                sira INTEGER NOT NULL DEFAULT 0,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
            );

            CREATE TABLE yorumlar (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gorev_id INTEGER NOT NULL,
                kullanici_id INTEGER NOT NULL,
                icerik TEXT NOT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE gorev_dosyalari (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gorev_id INTEGER NOT NULL,
                kullanici_id INTEGER NOT NULL,
                tur TEXT NOT NULL DEFAULT 'dosya' CHECK (tur IN ('dosya', 'link')),
                baslik TEXT NOT NULL,
                dosya_yolu TEXT NULL,
                harici_url TEXT NULL,
                dosya_boyutu INTEGER NOT NULL DEFAULT 0,
                mime_tipi TEXT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE bildirimler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kullanici_id INTEGER NOT NULL,
                tur TEXT NOT NULL,
                mesaj TEXT NOT NULL,
                link TEXT NULL,
                okundu INTEGER NOT NULL DEFAULT 0,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE aktiviteler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proje_id INTEGER NOT NULL,
                kullanici_id INTEGER NULL,
                mesaj TEXT NOT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours')),
                FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
            );

            CREATE TABLE sistem_migrasyonlari (
                ad TEXT PRIMARY KEY,
                uygulandi_tarihi TEXT NOT NULL DEFAULT (datetime('now', '+3 hours'))
            );
        ");

        $simdi = db_istanbul_simdi();
        $mig_stmt = $pdo->prepare('INSERT OR IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)');
        $mig_stmt->execute(array('tz_istanbul_v1', $simdi));
        $mig_stmt->execute(array('birim_aviyonik_v1', $simdi));
        $mig_stmt->execute(array('birim_aerodinamik_v1', $simdi));
    }
}

if (!function_exists('db_mysql_tablo_var_mi')) {
    function db_mysql_tablo_var_mi(PDO $pdo, $tablo_adi)
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(array($tablo_adi));
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('db_mysql_baslat')) {
    function db_mysql_baslat(PDO $pdo)
    {
        if (db_mysql_tablo_var_mi($pdo, 'kullanicilar')) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sistem_migrasyonlari (
                    ad VARCHAR(80) PRIMARY KEY,
                    uygulandi_tarihi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Idempotent zaman damgasi kaydirmasi: SET time_zone='+03:00' ONCESI sunucu saat farkini olc
            $tz_kontrol = $pdo->query("SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'tz_istanbul_v1' LIMIT 1");
            if (!$tz_kontrol || !$tz_kontrol->fetchColumn()) {
                $fark_stmt = $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS sunucu_ofset');
                $fark_satir = $fark_stmt ? $fark_stmt->fetch() : null;
                $sunucu_ofset = ($fark_satir && isset($fark_satir['sunucu_ofset'])) ? (int) $fark_satir['sunucu_ofset'] : 0;
                $kayma_sn = 10800 - $sunucu_ofset;

                $pdo->beginTransaction();
                try {
                    if ($kayma_sn !== 0) {
                        $kaydirma_listesi = array(
                            'kullanicilar' => array('olusturma_tarihi'),
                            'projeler' => array('olusturma_tarihi', 'duyuru_tarihi'),
                            'proje_uyeleri' => array('katilim_tarihi'),
                            'gorevler' => array('olusturma_tarihi', 'guncelleme_tarihi'),
                            'alt_gorevler' => array('olusturma_tarihi'),
                            'yorumlar' => array('olusturma_tarihi'),
                            'gorev_dosyalari' => array('olusturma_tarihi'),
                            'bildirimler' => array('olusturma_tarihi'),
                            'aktiviteler' => array('olusturma_tarihi'),
                            'hatirla_tokenlari' => array('olusturma_tarihi', 'son_gecerlilik'),
                        );
                        $kayma_int = (int) $kayma_sn;
                        foreach ($kaydirma_listesi as $tablo => $sutunlar) {
                            if (!db_mysql_tablo_var_mi($pdo, $tablo)) {
                                continue;
                            }
                            if ($tablo === 'gorevler') {
                                $pdo->exec(
                                    'UPDATE gorevler SET olusturma_tarihi = DATE_ADD(olusturma_tarihi, INTERVAL ' . $kayma_int . ' SECOND), guncelleme_tarihi = DATE_ADD(guncelleme_tarihi, INTERVAL ' . $kayma_int . ' SECOND)'
                                );
                                continue;
                            }
                            foreach ($sutunlar as $sutun) {
                                $pdo->exec(
                                    "UPDATE {$tablo} SET {$sutun} = DATE_ADD({$sutun}, INTERVAL {$kayma_int} SECOND) WHERE {$sutun} IS NOT NULL"
                                );
                            }
                        }
                    }

                    $kayit_stmt = $pdo->prepare('INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)');
                    $kayit_stmt->execute(array('tz_istanbul_v1', db_istanbul_simdi()));
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            $birim_kontrol = $pdo->query("SELECT 1 FROM sistem_migrasyonlari WHERE ad = 'birim_aviyonik_v1' LIMIT 1");
            if (!$birim_kontrol || !$birim_kontrol->fetchColumn()) {
                if (db_mysql_tablo_var_mi($pdo, 'gorevler')) {
                    $pdo->exec("
                        UPDATE gorevler
                        SET birim = 'aviyonik', guncelleme_tarihi = guncelleme_tarihi
                        WHERE LOWER(TRIM(birim)) IN ('tasarim', 'tasarım')
                    ");
                }
                $birim_stmt = $pdo->prepare('INSERT IGNORE INTO sistem_migrasyonlari (ad, uygulandi_tarihi) VALUES (?, ?)');
                $birim_stmt->execute(array('birim_aviyonik_v1', db_istanbul_simdi()));
            }

            db_birim_aerodinamik_migrasyonu_calistir($pdo, 'mysql');
            db_ekip_unvan_migrasyonu_calistir($pdo, 'mysql');
        }

        // InfinityFree MySQL'de named 'Europe/Istanbul' tablosu olmayabilecegi icin sayisal '+03:00' kullanilir
        try {
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            // Sunucu izin vermezse PHP tarafinda istanbul_simdi() zaten UTC+3 yazar
        }
    }
}

if (!function_exists('db_baglan')) {
    function db_baglan()
    {
        static $baglanti = null;
        if ($baglanti instanceof PDO) {
            return $baglanti;
        }

        $surucu = getenv('APP_DB_DRIVER');
        $secenekler = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        );

        try {
            if ($surucu === 'sqlite') {
                $depolama_dizini = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
                if (!is_dir($depolama_dizini)) {
                    mkdir($depolama_dizini, 0775, true);
                }
                $dosya_yolu = $depolama_dizini . DIRECTORY_SEPARATOR . 'test.sqlite';
                $baglanti = new PDO('sqlite:' . $dosya_yolu, null, null, $secenekler);
                db_sqlite_baslat($baglanti);
                return $baglanti;
            }

            $host = getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost';
            $db_adi = getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'gorev_takip';
            $kullanici = getenv('DB_USER') !== false ? getenv('DB_USER') : 'root';
            $sifre = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '12345678';

            $dsn = 'mysql:host=' . $host . ';dbname=' . $db_adi . ';charset=utf8mb4';
            $baglanti = new PDO($dsn, $kullanici, $sifre, $secenekler);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Veritabanı bağlantısı kurulamadı. Lütfen yapılandırma ayarlarını kontrol edin.');
        }

        try {
            db_mysql_baslat($baglanti);
        } catch (Exception $e) {
            // Baglanti basariliysa migrasyon hatasi siteyi tamamen kapatmasin
        }

        return $baglanti;
    }
}

$pdo = db_baglan();
