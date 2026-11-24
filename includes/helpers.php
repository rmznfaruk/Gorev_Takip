<?php
/**
 * Genel yardimci fonksiyonlar.
 */

function e($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function flash_ekle($tur, $mesaj)
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = array();
    }
    $_SESSION['flash'][] = array(
        'tur' => $tur,
        'mesaj' => $mesaj,
    );
}

function flash_al()
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return array();
    }
    $mesajlar = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $mesajlar;
}

function durum_etiketi($durum)
{
    $harita = array(
        'yapilacak' => array('metin' => 'Yapılacak', 'sinif' => 'badge-yapilacak'),
        'devam_ediyor' => array('metin' => 'Devam Ediyor', 'sinif' => 'badge-devam'),
        'tamamlandi' => array('metin' => 'Tamamlandı', 'sinif' => 'badge-tamamlandi'),
    );
    if (isset($harita[$durum])) {
        return $harita[$durum];
    }
    return array('metin' => (string) $durum, 'sinif' => 'badge-yapilacak');
}

function proje_durum_etiketi($durum)
{
    $harita = array(
        'aktif' => array('metin' => 'Aktif', 'sinif' => 'badge-devam'),
        'tamamlandi' => array('metin' => 'Tamamlandı', 'sinif' => 'badge-tamamlandi'),
        'arsiv' => array('metin' => 'Arşiv', 'sinif' => 'badge-yapilacak'),
    );
    if (isset($harita[$durum])) {
        return $harita[$durum];
    }
    return array('metin' => (string) $durum, 'sinif' => 'badge-yapilacak');
}

function oncelik_etiketi($oncelik)
{
    $harita = array(
        'dusuk' => array('metin' => 'Düşük', 'sinif' => 'badge-dusuk'),
        'orta' => array('metin' => 'Orta', 'sinif' => 'badge-orta'),
        'yuksek' => array('metin' => 'Yüksek', 'sinif' => 'badge-yuksek'),
    );
    if (isset($harita[$oncelik])) {
        return $harita[$oncelik];
    }
    return array('metin' => (string) $oncelik, 'sinif' => 'badge-orta');
}

function istanbul_simdi($ofset_saniye = 0)
{
    try {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
        $simdi = new DateTimeImmutable('now', $tz);
        $ofset = (int) $ofset_saniye;
        if ($ofset !== 0) {
            $simdi = $simdi->modify(($ofset >= 0 ? '+' : '') . $ofset . ' seconds');
        }
        return $simdi->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return date('Y-m-d H:i:s', time() + (int) $ofset_saniye);
    }
}

function tarih_format($tarih, $saat_goster = false)
{
    if ($tarih === null || is_bool($tarih) || (!is_string($tarih) && !is_numeric($tarih))) {
        return '-';
    }

    $ham = trim((string) $tarih);
    if ($ham === '' || $ham === '0' || strpos($ham, '0000-00-00') === 0) {
        return '-';
    }

    $aylar = array(
        1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis',
        5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu',
        9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara',
    );

    try {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
        $dt = new DateTimeImmutable($ham, $tz);
        $dt = $dt->setTimezone($tz);
        $gun = $dt->format('d');
        $ay_no = (int) $dt->format('n');
        $ay = isset($aylar[$ay_no]) ? $aylar[$ay_no] : $dt->format('m');
        $yil = $dt->format('Y');
        if ($saat_goster) {
            return $gun . ' ' . $ay . ' ' . $yil . ' ' . $dt->format('H:i');
        }
        return $gun . ' ' . $ay . ' ' . $yil;
    } catch (Exception $e) {
        $zaman = strtotime($ham);
        if ($zaman === false) {
            return (string) $tarih;
        }
        $gun = date('d', $zaman);
        $ay = isset($aylar[(int) date('n', $zaman)]) ? $aylar[(int) date('n', $zaman)] : date('m', $zaman);
        $yil = date('Y', $zaman);

        if ($saat_goster) {
            return $gun . ' ' . $ay . ' ' . $yil . ' ' . date('H:i', $zaman);
        }
        return $gun . ' ' . $ay . ' ' . $yil;
    }
}

function bas_harfler($ad)
{
    $temiz = trim((string) $ad);
    if ($temiz === '') {
        return 'GT';
    }
    $parcalar = @preg_split('/\s+/u', $temiz);
    if (!is_array($parcalar)) {
        $parcalar = preg_split('/\s+/', $temiz);
    }
    if (!is_array($parcalar)) {
        $parcalar = array($temiz);
    }
    $harfler = '';
    $sayac = 0;
    foreach ($parcalar as $parca) {
        if ($parca === '') {
            continue;
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $harfler .= mb_strtoupper(mb_substr($parca, 0, 1, 'UTF-8'), 'UTF-8');
        } else {
            $harfler .= strtoupper(substr($parca, 0, 1));
        }
        $sayac++;
        if ($sayac >= 2) {
            break;
        }
    }
    return $harfler !== '' ? $harfler : 'GT';
}

function yonlendir($url)
{
    header('Location: ' . $url);
    exit;
}

function dosya_boyutu_format($bayt)
{
    $bayt = (int) $bayt;
    if ($bayt < 1024) {
        return $bayt . ' B';
    }
    if ($bayt < 1048576) {
        return number_format($bayt / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bayt / 1048576, 2, ',', '.') . ' MB';
}

function birim_secenekleri()
{
    return array(
        'genel' => 'Genel',
        'yazilim' => 'Yazılım',
        'elektronik' => 'Elektronik',
        'mekanik' => 'Mekanik',
        'raporlama' => 'Raporlama',
        'aviyonik' => 'Aviyonik',
    );
}

function birim_kodu_normalize($birim, $varsayilan = 'genel')
{
    if (!is_string($birim) && !is_numeric($birim)) {
        return $varsayilan;
    }
    $temiz = trim((string) $birim);
    if ($temiz === '') {
        return $varsayilan;
    }
    $anahtar = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    if (in_array($anahtar, array('tasarim', 'tasarım', "tasari\xCC\x87m", "avi\xCC\x87yoni\xCC\x87k", "avi\xCC\x87yonik", "aviyoni\xCC\x87k"), true)) {
        return 'aviyonik';
    }
    $secenekler = birim_secenekleri();
    return isset($secenekler[$anahtar]) ? $anahtar : $varsayilan;
}

function birim_filtre_degerleri($birim_kodu)
{
    $kod = birim_kodu_normalize($birim_kodu, '');
    if ($kod === '') {
        return array();
    }
    if ($kod === 'aviyonik') {
        return array('aviyonik', 'tasarim', 'Tasarim', 'tasarım', 'Tasarım', 'TASARIM', 'TASARİM', "tasari\xCC\x87m", "TASARI\xCC\x87M");
    }
    return array($kod);
}

function birim_etiketi($birim)
{
    $secenekler = birim_secenekleri();
    $anahtar = birim_kodu_normalize($birim, 'genel');
    return array(
        'kod' => $anahtar,
        'metin' => $secenekler[$anahtar],
        'sinif' => 'unit-' . $anahtar,
    );
}

function birim_gecerli_mi($birim)
{
    if (!is_string($birim)) {
        return false;
    }
    $secenekler = birim_secenekleri();
    $temiz = trim($birim);
    $anahtar = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    return isset($secenekler[$anahtar]);
}

function oda_kodu_uret(PDO $pdo, $on_ek = 'ODA')
{
    $temiz_on_ek = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $on_ek));
    if ($temiz_on_ek === '') {
        $temiz_on_ek = defined('ODA_KODU_ON_EK') ? ODA_KODU_ON_EK : 'ODA';
    }
    if (strlen($temiz_on_ek) > 6) {
        $temiz_on_ek = substr($temiz_on_ek, 0, 6);
    }

    // Karisabilen 0/O/1/I karakterleri haric 32 karakterlik alfabe (modulo sapmasi yok)
    $alfabe = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $kontrol = $pdo->prepare('SELECT id FROM projeler WHERE davet_kodu = ? LIMIT 1');

    for ($deneme = 0; $deneme < 30; $deneme++) {
        $baytlar = random_bytes(6);
        $parca = '';
        for ($i = 0; $i < 6; $i++) {
            $indeks = ord($baytlar[$i]) & 31;
            $parca .= $alfabe[$indeks];
        }
        $kod = $temiz_on_ek . '-' . $parca;
        $kontrol->execute(array($kod));
        if (!$kontrol->fetch()) {
            return $kod;
        }
    }

    // Cok dusuk olasilikli yedek
    return $temiz_on_ek . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function metin_kisalt($metin, $max_uzunluk = 255)
{
    $temiz = trim((string) $metin);
    if (function_exists('mb_substr')) {
        return mb_substr($temiz, 0, (int) $max_uzunluk, 'UTF-8');
    }
    return substr($temiz, 0, (int) $max_uzunluk);
}

function bildirim_ekle(PDO $pdo, $hedef_kullanici_id, $tur, $mesaj, $link = null)
{
    $hedef_id = (int) $hedef_kullanici_id;
    if ($hedef_id <= 0) {
        return false;
    }
    // Kullaniciya kendi yaptigi islem icin bildirim gitmez
    if (isset($_SESSION['kullanici_id']) && (int) $_SESSION['kullanici_id'] === $hedef_id) {
        return false;
    }

    $tur_temiz = metin_kisalt($tur !== '' ? $tur : 'genel', 40);
    $mesaj_temiz = metin_kisalt($mesaj, 255);
    if ($mesaj_temiz === '') {
        return false;
    }
    $link_temiz = ($link !== null && trim((string) $link) !== '') ? metin_kisalt($link, 255) : null;

    try {
        $stmt = $pdo->prepare('
            INSERT INTO bildirimler (kullanici_id, tur, mesaj, link, okundu, olusturma_tarihi)
            VALUES (?, ?, ?, ?, 0, ?)
        ');
        $ok = $stmt->execute(array($hedef_id, $tur_temiz, $mesaj_temiz, $link_temiz, istanbul_simdi()));
        if ($ok) {
            // Kullanici basina en guncel 100 bildirimi tut, eskilerini temizle
            $esik_stmt = $pdo->prepare('
                SELECT id FROM bildirimler
                WHERE kullanici_id = ?
                ORDER BY id DESC
                LIMIT 1 OFFSET 100
            ');
            $esik_stmt->execute(array($hedef_id));
            $esik_id = $esik_stmt->fetchColumn();
            if ($esik_id !== false) {
                $sil_stmt = $pdo->prepare('DELETE FROM bildirimler WHERE kullanici_id = ? AND id <= ?');
                $sil_stmt->execute(array($hedef_id, (int) $esik_id));
            }
        }
        return $ok;
    } catch (PDOException $e) {
        return false;
    }
}

function aktivite_kaydet(PDO $pdo, $proje_id, $kullanici_id, $mesaj)
{
    $pid = (int) $proje_id;
    if ($pid <= 0) {
        return false;
    }
    $kid = ($kullanici_id !== null && (int) $kullanici_id > 0) ? (int) $kullanici_id : null;
    $mesaj_temiz = metin_kisalt($mesaj, 255);
    if ($mesaj_temiz === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO aktiviteler (proje_id, kullanici_id, mesaj, olusturma_tarihi)
            VALUES (?, ?, ?, ?)
        ');
        $ok = $stmt->execute(array($pid, $kid, $mesaj_temiz, istanbul_simdi()));
        if ($ok) {
            // Oda basina en guncel 200 aktiviteyi tut, eskilerini otomatik temizle
            $esik_stmt = $pdo->prepare('
                SELECT id FROM aktiviteler
                WHERE proje_id = ?
                ORDER BY id DESC
                LIMIT 1 OFFSET 200
            ');
            $esik_stmt->execute(array($pid));
            $esik_id = $esik_stmt->fetchColumn();
            if ($esik_id !== false) {
                $sil_stmt = $pdo->prepare('DELETE FROM aktiviteler WHERE proje_id = ? AND id <= ?');
                $sil_stmt->execute(array($pid, (int) $esik_id));
            }
        }
        return $ok;
    } catch (PDOException $e) {
        return false;
    }
}

function gorev_ilerleme_hesapla($gorev, $alt_toplam = 0, $alt_tamamlanan = 0)
{
    $toplam = (int) $alt_toplam;
    $tamamlanan = (int) $alt_tamamlanan;
    if ($toplam > 0) {
        $oran = (int) round(($tamamlanan / $toplam) * 100);
        if ($oran < 0) {
            return 0;
        }
        if ($oran > 100) {
            return 100;
        }
        return $oran;
    }

    $durum = is_array($gorev) && isset($gorev['durum']) ? (string) $gorev['durum'] : (string) $gorev;
    if ($durum === 'tamamlandi') {
        return 100;
    }
    if ($durum === 'devam_ediyor') {
        return 50;
    }
    return 0;
}

function gorev_listesi_ortalama_ilerleme($gorevler)
{
    if (!is_array($gorevler) || empty($gorevler)) {
        return 0;
    }
    $toplam_yuzde = 0;
    $adet = 0;
    foreach ($gorevler as $g) {
        $alt_toplam = is_array($g) && isset($g['alt_toplam']) ? (int) $g['alt_toplam'] : 0;
        $alt_tamamlanan = is_array($g) && isset($g['alt_tamamlanan']) ? (int) $g['alt_tamamlanan'] : 0;
        $toplam_yuzde += gorev_ilerleme_hesapla($g, $alt_toplam, $alt_tamamlanan);
        $adet++;
    }
    if ($adet <= 0) {
        return 0;
    }
    $ortalama = (int) round($toplam_yuzde / $adet);
    if ($ortalama < 0) {
        return 0;
    }
    if ($ortalama > 100) {
        return 100;
    }
    return $ortalama;
}

function gorev_durumu_alt_gorevlerle_senkronla(PDO $pdo, $gorev_id, $mevcut_durum = null)
{
    $gid = (int) $gorev_id;
    if ($mevcut_durum === null) {
        $g_stmt = $pdo->prepare('SELECT durum FROM gorevler WHERE id = ? LIMIT 1');
        $g_stmt->execute(array($gid));
        $g_satir = $g_stmt->fetch();
        $mevcut_durum = $g_satir ? (string) $g_satir['durum'] : 'yapilacak';
    } else {
        $mevcut_durum = (string) $mevcut_durum;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS toplam,
               SUM(CASE WHEN tamamlandi = 1 THEN 1 ELSE 0 END) AS tamamlanan
        FROM alt_gorevler
        WHERE gorev_id = ?
    ');
    $stmt->execute(array($gid));
    $ozet = $stmt->fetch();
    $toplam = $ozet ? (int) $ozet['toplam'] : 0;
    $tamamlanan = ($ozet && $ozet['tamamlanan'] !== null) ? (int) $ozet['tamamlanan'] : 0;

    $yeni_durum = $mevcut_durum;
    if ($toplam > 0 && $tamamlanan === $toplam) {
        $yeni_durum = 'tamamlandi';
    } elseif ($toplam > 0 && $tamamlanan > 0 && $tamamlanan < $toplam) {
        $yeni_durum = 'devam_ediyor';
    } elseif ($toplam > 0 && $tamamlanan === 0 && $mevcut_durum === 'tamamlandi') {
        $yeni_durum = 'devam_ediyor';
    }

    if ($yeni_durum !== $mevcut_durum) {
        $guncelle = $pdo->prepare('UPDATE gorevler SET durum = ?, guncelleme_tarihi = ? WHERE id = ?');
        $guncelle->execute(array($yeni_durum, istanbul_simdi(), $gid));
    }

    $ilerleme = gorev_ilerleme_hesapla(array('durum' => $yeni_durum), $toplam, $tamamlanan);

    return array(
        'alt_toplam' => $toplam,
        'alt_tamamlanan' => $tamamlanan,
        'ilerleme' => $ilerleme,
        'gorev_durum' => $yeni_durum,
    );
}

function teslim_durumu_hesapla($son_tarih, $durum = 'yapilacak')
{
    if ($durum === 'tamamlandi' || empty($son_tarih)) {
        return array(
            'durum' => 'normal',
            'metin' => '',
            'kisa' => '',
            'gun_farki' => null,
            'sinif' => '',
        );
    }

    $tarih_str = substr(trim((string) $son_tarih), 0, 10);
    $hedef_ts = strtotime($tarih_str . ' 00:00:00');
    $bugun_ts = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($hedef_ts === false || $bugun_ts === false) {
        return array(
            'durum' => 'normal',
            'metin' => '',
            'kisa' => '',
            'gun_farki' => null,
            'sinif' => '',
        );
    }

    $fark = (int) round(($hedef_ts - $bugun_ts) / 86400);

    if ($fark < 0) {
        $gecen = abs($fark);
        return array(
            'durum' => 'gecikti',
            'metin' => $gecen . ' gün gecikti',
            'kisa' => 'Gecikti',
            'gun_farki' => $fark,
            'sinif' => 'deadline-overdue',
        );
    }
    if ($fark === 0) {
        return array(
            'durum' => 'bugun',
            'metin' => 'Bugün son gün',
            'kisa' => 'Bugün',
            'gun_farki' => 0,
            'sinif' => 'deadline-today',
        );
    }
    if ($fark === 1) {
        return array(
            'durum' => 'yarin',
            'metin' => 'Yarın',
            'kisa' => 'Yarın',
            'gun_farki' => 1,
            'sinif' => 'deadline-soon',
        );
    }
    if ($fark <= 7) {
        return array(
            'durum' => 'yaklasiyor',
            'metin' => $fark . ' gün kaldı',
            'kisa' => $fark . ' gün',
            'gun_farki' => $fark,
            'sinif' => 'deadline-soon',
        );
    }

    return array(
        'durum' => 'normal',
        'metin' => $fark . ' gün kaldı',
        'kisa' => $fark . ' gün',
        'gun_farki' => $fark,
        'sinif' => 'deadline-normal',
    );
}

function kullanici_odalari_getir(PDO $pdo, $kullanici)
{
    $kullanici_id = is_array($kullanici) && isset($kullanici['id'])
        ? (int) $kullanici['id']
        : (int) $kullanici;

    if ($kullanici_id <= 0) {
        return array();
    }

    $stmt = $pdo->prepare('
        SELECT p.id, p.ad, p.kod, p.davet_kodu, p.durum, p.yonetici_id,
               COALESCE(pu.yetki, \'uye\') AS yetki
        FROM projeler p
        LEFT JOIN proje_uyeleri pu ON pu.proje_id = p.id AND pu.kullanici_id = ?
        WHERE p.yonetici_id = ? OR pu.kullanici_id IS NOT NULL
        ORDER BY p.olusturma_tarihi DESC
        LIMIT 20
    ');
    $stmt->execute(array($kullanici_id, $kullanici_id));
    return $stmt->fetchAll();
}

function okunmamis_bildirim_sayisi(PDO $pdo, $kullanici_id)
{
    $kid = (int) $kullanici_id;
    if ($kid <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bildirimler WHERE kullanici_id = ? AND okundu = 0');
        $stmt->execute(array($kid));
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
