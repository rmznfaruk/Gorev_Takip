<?php
/**
 * Ekip Lideri / Yonetici tek tikla veritabani yedegi (.sql) indirme uc noktasi.
 * exec() / mysqldump kullanmadan tamamen PDO uzerinden UTF-8 SQL ciktisi uretir.
 * Global yonetici olmayan Ekip Liderleri icin siki cok-kiracili oda izolasyonu uygular.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Bu işlem yalnızca POST isteği ile gerçekleştirilebilir.');
}

if (!kullanici_ekip_lideri_mi($pdo, $kullanici)) {
    http_response_code(403);
    exit('Bu işlem için Ekip Lideri yetkisi gereklidir.');
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!csrf_dogrula($token)) {
    http_response_code(403);
    exit('Güvenlik doğrulaması (CSRF) başarısız oldu.');
}

function sql_deger_hazirla($deger)
{
    if ($deger === null) {
        return 'NULL';
    }
    if (is_int($deger) || is_float($deger)) {
        return (string) $deger;
    }
    $metin = (string) $deger;
    $arama = array("\\", "\0", "\n", "\r", "'", "\x1a");
    $degistir = array("\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z");
    return "'" . str_replace($arama, $degistir, $metin) . "'";
}

function yedek_tablo_satirlari_ekle(&$satirlar, $tablo, $kayitlar, $temizle_ekle)
{
    $satirlar[] = '-- --------------------------------------------------------';
    $satirlar[] = '-- Tablo: `' . $tablo . '`';
    $satirlar[] = '-- --------------------------------------------------------';
    if ($temizle_ekle) {
        $satirlar[] = 'DELETE FROM `' . $tablo . '`;';
    }

    if (!empty($kayitlar)) {
        $sutunlar = array_keys($kayitlar[0]);
        $sutun_listesi = array();
        foreach ($sutunlar as $sutun) {
            $sutun_listesi[] = '`' . str_replace('`', '``', $sutun) . '`';
        }
        $sutun_sql = implode(', ', $sutun_listesi);

        foreach ($kayitlar as $kayit) {
            $degerler = array();
            foreach ($sutunlar as $sutun) {
                $val = array_key_exists($sutun, $kayit) ? $kayit[$sutun] : null;
                $degerler[] = sql_deger_hazirla($val);
            }
            $satirlar[] = 'INSERT INTO `' . $tablo . '` (' . $sutun_sql . ') VALUES (' . implode(', ', $degerler) . ');';
        }
    }

    $satirlar[] = '';
}

$global_yonetici = yonetici_mi($kullanici);
$tarih_damgasi = date('Y-m-d_H-i');
$dosya_adi = ($global_yonetici ? 'gorevtakip_yedek_' : 'gorevtakip_oda_yedek_') . $tarih_damgasi . '.sql';

$satirlar = array();
$satirlar[] = '-- GörevTakip Veritabanı Yedeği';
$satirlar[] = '-- Kapsam: ' . ($global_yonetici ? 'Tam Veritabanı (9 Tablo)' : 'Oda Bazlı Yedek (Yalnızca Kurucusu Olduğunuz Odalar)');
$satirlar[] = '-- Oluşturma Tarihi: ' . date('Y-m-d H:i:s');
$satirlar[] = '-- Oluşturan: ' . (isset($kullanici['eposta']) ? $kullanici['eposta'] : 'ekip_lideri');
$satirlar[] = '';
$satirlar[] = 'SET NAMES utf8mb4;';
$satirlar[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$satirlar[] = '';

if ($global_yonetici) {
    $tablolar = array(
        'kullanicilar',
        'projeler',
        'proje_uyeleri',
        'gorevler',
        'alt_gorevler',
        'yorumlar',
        'gorev_dosyalari',
        'bildirimler',
        'aktiviteler',
    );

    foreach ($tablolar as $tablo) {
        $stmt = $pdo->query('SELECT * FROM `' . $tablo . '`');
        $kayitlar = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        yedek_tablo_satirlari_ekle($satirlar, $tablo, $kayitlar, true);
    }
} else {
    // Siki cok-kiracili izolasyon: Yalnizca kullanicinin kurdugu (yonetici_id = $kullanici_id) odalarin verileri
    $proje_stmt = $pdo->prepare('SELECT * FROM `projeler` WHERE `yonetici_id` = ? ORDER BY `id` ASC');
    $proje_stmt->execute(array($kullanici_id));
    $proje_kayitlari = $proje_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($proje_kayitlari)) {
        http_response_code(403);
        exit('Dışa aktarılacak kurulu çalışma odanız bulunmuyor.');
    }

    $oda_idleri = array();
    foreach ($proje_kayitlari as $proje_satir) {
        $oda_idleri[] = (int) $proje_satir['id'];
    }
    $oda_ph = implode(',', array_fill(0, count($oda_idleri), '?'));

    $uyeler_stmt = $pdo->prepare('SELECT * FROM `proje_uyeleri` WHERE `proje_id` IN (' . $oda_ph . ') ORDER BY `id` ASC');
    $uyeler_stmt->execute($oda_idleri);
    $uye_kayitlari = $uyeler_stmt->fetchAll(PDO::FETCH_ASSOC);

    $gorevler_stmt = $pdo->prepare('SELECT * FROM `gorevler` WHERE `proje_id` IN (' . $oda_ph . ') ORDER BY `id` ASC');
    $gorevler_stmt->execute($oda_idleri);
    $gorev_kayitlari = $gorevler_stmt->fetchAll(PDO::FETCH_ASSOC);

    $gorev_idleri = array();
    foreach ($gorev_kayitlari as $gorev_satir) {
        $gorev_idleri[] = (int) $gorev_satir['id'];
    }

    $alt_gorev_kayitlari = array();
    $yorum_kayitlari = array();
    $dosya_kayitlari = array();

    if (!empty($gorev_idleri)) {
        $gorev_ph = implode(',', array_fill(0, count($gorev_idleri), '?'));

        $alt_stmt = $pdo->prepare('SELECT * FROM `alt_gorevler` WHERE `gorev_id` IN (' . $gorev_ph . ') ORDER BY `id` ASC');
        $alt_stmt->execute($gorev_idleri);
        $alt_gorev_kayitlari = $alt_stmt->fetchAll(PDO::FETCH_ASSOC);

        $yorum_stmt = $pdo->prepare('SELECT * FROM `yorumlar` WHERE `gorev_id` IN (' . $gorev_ph . ') ORDER BY `id` ASC');
        $yorum_stmt->execute($gorev_idleri);
        $yorum_kayitlari = $yorum_stmt->fetchAll(PDO::FETCH_ASSOC);

        $dosya_stmt = $pdo->prepare('SELECT * FROM `gorev_dosyalari` WHERE `gorev_id` IN (' . $gorev_ph . ') ORDER BY `id` ASC');
        $dosya_stmt->execute($gorev_idleri);
        $dosya_kayitlari = $dosya_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $akt_stmt = $pdo->prepare('SELECT * FROM `aktiviteler` WHERE `proje_id` IN (' . $oda_ph . ') ORDER BY `id` ASC');
    $akt_stmt->execute($oda_idleri);
    $aktivite_kayitlari = $akt_stmt->fetchAll(PDO::FETCH_ASSOC);

    yedek_tablo_satirlari_ekle($satirlar, 'projeler', $proje_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'proje_uyeleri', $uye_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'gorevler', $gorev_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'alt_gorevler', $alt_gorev_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'yorumlar', $yorum_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'gorev_dosyalari', $dosya_kayitlari, false);
    yedek_tablo_satirlari_ekle($satirlar, 'aktiviteler', $aktivite_kayitlari, false);
}

$satirlar[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$satirlar[] = '';

$sql_icerik = implode("\n", $satirlar);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dosya_adi . '"');
header('Content-Length: ' . strlen($sql_icerik));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo $sql_icerik;
exit;
