<?php
/**
 * Veritabani baglanti yapilandirmasi.
 * Varsayilan olarak MySQL (PDO) kullanir.
 * Yerel test icin APP_DB_DRIVER=sqlite ortam degiskeni ile SQLite destegi sunar.
 */

require_once __DIR__ . '/app.php';

if (!function_exists('db_sqlite_baslat')) {
    function db_sqlite_baslat(PDO $pdo)
    {
        $pdo->exec('PRAGMA foreign_keys = ON;');

        $kontrol = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='kullanicilar'");
        if ($kontrol && $kontrol->fetch()) {
            // Mevcut SQLite veritabani varsa yeni v3 tablo/sutunlari guvenle tamamla
            $alt_tablo = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='alt_gorevler'");
            if (!$alt_tablo || !$alt_tablo->fetch()) {
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
                        olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                        FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
                    );
                ");
            }

            $bildirim_tablo = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bildirimler'");
            if (!$bildirim_tablo || !$bildirim_tablo->fetch()) {
                $pdo->exec("
                    CREATE TABLE bildirimler (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        kullanici_id INTEGER NOT NULL,
                        tur TEXT NOT NULL,
                        mesaj TEXT NOT NULL,
                        link TEXT NULL,
                        okundu INTEGER NOT NULL DEFAULT 0,
                        olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
                    );
                ");
            }

            $aktivite_tablo = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='aktiviteler'");
            if (!$aktivite_tablo || !$aktivite_tablo->fetch()) {
                $pdo->exec("
                    CREATE TABLE aktiviteler (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        proje_id INTEGER NOT NULL,
                        kullanici_id INTEGER NULL,
                        mesaj TEXT NOT NULL,
                        olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                        FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
                    );
                ");
            }
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
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (yonetici_id) REFERENCES kullanicilar(id) ON DELETE RESTRICT ON UPDATE CASCADE
            );

            CREATE TABLE proje_uyeleri (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proje_id INTEGER NOT NULL,
                kullanici_id INTEGER NOT NULL,
                proje_rolu TEXT NOT NULL DEFAULT 'Ekip Üyesi',
                yetki TEXT NOT NULL DEFAULT 'uye',
                katilim_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                birim TEXT NOT NULL DEFAULT 'genel',
                son_tarih TEXT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                guncelleme_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (gorev_id) REFERENCES gorevler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (atanan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
            );

            CREATE TABLE yorumlar (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gorev_id INTEGER NOT NULL,
                kullanici_id INTEGER NOT NULL,
                icerik TEXT NOT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE ON UPDATE CASCADE
            );

            CREATE TABLE aktiviteler (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                proje_id INTEGER NOT NULL,
                kullanici_id INTEGER NULL,
                mesaj TEXT NOT NULL,
                olusturma_tarihi TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL ON UPDATE CASCADE
            );
        ");
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
            return $baglanti;
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Veritabanı bağlantısı kurulamadı. Lütfen yapılandırma ayarlarını kontrol edin.');
        }
    }
}

$pdo = db_baglan();
