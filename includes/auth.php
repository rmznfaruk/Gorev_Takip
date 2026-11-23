<?php
/**
 * Oturum yonetimi, kimlik dogrulama ve CSRF korumasi.
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/helpers.php';

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}

function giris_yapili_mi()
{
    return isset($_SESSION['kullanici_id']) && (int) $_SESSION['kullanici_id'] > 0;
}

function aktif_kullanici(PDO $pdo)
{
    static $onbellek = null;
    static $onbellek_id = null;

    if (!giris_yapili_mi()) {
        return null;
    }

    $id = (int) $_SESSION['kullanici_id'];
    if ($onbellek !== null && $onbellek_id === $id) {
        return $onbellek;
    }

    $stmt = $pdo->prepare('SELECT id, ad_soyad, eposta, rol, unvan, olusturma_tarihi FROM kullanicilar WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $kullanici = $stmt->fetch();

    if (!$kullanici) {
        cikis_yap();
        return null;
    }

    $_SESSION['kullanici_rol'] = $kullanici['rol'];
    $_SESSION['kullanici_ad'] = $kullanici['ad_soyad'];
    $onbellek = $kullanici;
    $onbellek_id = $id;
    return $kullanici;
}

function yonetici_mi($kullanici = null)
{
    if (is_array($kullanici) && isset($kullanici['rol'])) {
        return $kullanici['rol'] === 'yonetici';
    }
    return isset($_SESSION['kullanici_rol']) && $_SESSION['kullanici_rol'] === 'yonetici';
}

function kullanici_ekip_lideri_mi(PDO $pdo, $kullanici = null)
{
    if (yonetici_mi($kullanici)) {
        return true;
    }

    $kullanici_id = 0;
    if (is_array($kullanici) && isset($kullanici['id'])) {
        $kullanici_id = (int) $kullanici['id'];
    } elseif (is_numeric($kullanici)) {
        $kullanici_id = (int) $kullanici;
    } elseif (isset($_SESSION['kullanici_id'])) {
        $kullanici_id = (int) $_SESSION['kullanici_id'];
    }

    if ($kullanici_id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM projeler WHERE yonetici_id = ? LIMIT 1');
    $stmt->execute(array($kullanici_id));
    return (bool) $stmt->fetchColumn();
}

function giris_gerekli()
{
    if (!giris_yapili_mi()) {
        flash_ekle('error', 'Bu sayfayı görüntülemek için giriş yapmanız gerekiyor.');
        yonlendir('login.php');
    }
}

function api_giris_gerekli()
{
    if (!giris_yapili_mi()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(array(
            'basarili' => false,
            'mesaj' => 'Bu işlem için oturum açmanız gerekiyor.',
        ));
        exit;
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_dogrula($token)
{
    if (empty($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function cikis_yap()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function proje_erisim_var_mi(PDO $pdo, $proje_id, $kullanici)
{
    $proje_id = (int) $proje_id;
    $kullanici_id = is_array($kullanici) && isset($kullanici['id'])
        ? (int) $kullanici['id']
        : (int) $kullanici;

    if ($proje_id <= 0 || $kullanici_id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('
        SELECT p.id
        FROM projeler p
        WHERE p.id = ?
          AND (
              p.yonetici_id = ?
              OR EXISTS (
                  SELECT 1 FROM proje_uyeleri pu
                  WHERE pu.proje_id = p.id AND pu.kullanici_id = ?
              )
          )
        LIMIT 1
    ');
    $stmt->execute(array($proje_id, $kullanici_id, $kullanici_id));
    return (bool) $stmt->fetch();
}

function proje_uyesi_mi(PDO $pdo, $proje_id, $kullanici_id)
{
    return proje_erisim_var_mi($pdo, $proje_id, (int) $kullanici_id);
}

function proje_yonetebilir_mi($proje, $kullanici)
{
    if (!is_array($proje) || !is_array($kullanici) || !isset($kullanici['id'])) {
        return false;
    }
    $kullanici_id = (int) $kullanici['id'];
    if (isset($proje['yonetici_id']) && (int) $proje['yonetici_id'] === $kullanici_id) {
        return true;
    }
    if (isset($proje['proje_yonetici_id']) && (int) $proje['proje_yonetici_id'] === $kullanici_id) {
        return true;
    }
    return yonetici_mi($kullanici);
}

function proje_kurucu_veya_yonetici_mi($proje, $kullanici)
{
    return proje_yonetebilir_mi($proje, $kullanici);
}

function proje_lider_yetkisi_var_mi(PDO $pdo, $proje, $kullanici)
{
    if (!is_array($kullanici) || !isset($kullanici['id'])) {
        return false;
    }
    $kullanici_id = (int) $kullanici['id'];
    $proje_id = 0;
    if (is_array($proje)) {
        if (isset($proje['proje_id']) && !isset($proje['davet_kodu'])) {
            $proje_id = (int) $proje['proje_id'];
        } elseif (isset($proje['id'])) {
            $proje_id = (int) $proje['id'];
        }
    } else {
        $proje_id = (int) $proje;
    }

    if ($proje_id <= 0 || $kullanici_id <= 0) {
        return false;
    }

    // Siki oda izolasyonu: once oda erisimi olmali
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
        return false;
    }

    if (is_array($proje) && proje_yonetebilir_mi($proje, $kullanici)) {
        return true;
    }

    if (yonetici_mi($kullanici)) {
        return true;
    }

    $kurucu_stmt = $pdo->prepare('SELECT yonetici_id FROM projeler WHERE id = ? LIMIT 1');
    $kurucu_stmt->execute(array($proje_id));
    $kurucu = $kurucu_stmt->fetch();
    if ($kurucu && (int) $kurucu['yonetici_id'] === $kullanici_id) {
        return true;
    }

    $uye_stmt = $pdo->prepare('SELECT yetki FROM proje_uyeleri WHERE proje_id = ? AND kullanici_id = ? LIMIT 1');
    $uye_stmt->execute(array($proje_id, $kullanici_id));
    $uye = $uye_stmt->fetch();
    return $uye && isset($uye['yetki']) && $uye['yetki'] === 'lider';
}

function proje_yoneticisi_mi(PDO $pdo, $proje_id, $kullanici_id)
{
    $proje_id = (int) $proje_id;
    $kullanici_id = (int) $kullanici_id;
    if ($proje_id <= 0 || $kullanici_id <= 0) {
        return false;
    }
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici_id)) {
        return false;
    }
    if (yonetici_mi()) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT yonetici_id FROM projeler WHERE id = ? LIMIT 1');
    $stmt->execute(array($proje_id));
    $proje = $stmt->fetch();
    return $proje && (int) $proje['yonetici_id'] === $kullanici_id;
}

function giris_denemelerini_temizle()
{
    $pencere_saniye = 600; // 10 dakika
    $simdi = time();
    if (!isset($_SESSION['giris_denemeleri']) || !is_array($_SESSION['giris_denemeleri'])) {
        $_SESSION['giris_denemeleri'] = array();
        return array();
    }
    $gecerli = array();
    foreach ($_SESSION['giris_denemeleri'] as $zaman) {
        $zaman = (int) $zaman;
        if (($simdi - $zaman) < $pencere_saniye) {
            $gecerli[] = $zaman;
        }
    }
    $_SESSION['giris_denemeleri'] = $gecerli;
    return $gecerli;
}

function giris_denemesi_kilitli_mi()
{
    $denemeler = giris_denemelerini_temizle();
    return count($denemeler) >= 5;
}

function giris_kilit_kalan_dakika()
{
    $denemeler = giris_denemelerini_temizle();
    if (empty($denemeler)) {
        return 0;
    }
    $en_eski = min($denemeler);
    $kalan_saniye = 600 - (time() - $en_eski);
    if ($kalan_saniye <= 0) {
        return 0;
    }
    return (int) ceil($kalan_saniye / 60);
}

function giris_basarisiz_kaydet()
{
    $denemeler = giris_denemelerini_temizle();
    $denemeler[] = time();
    $_SESSION['giris_denemeleri'] = $denemeler;
}

function giris_denemesi_sifirla()
{
    unset($_SESSION['giris_denemeleri']);
}
