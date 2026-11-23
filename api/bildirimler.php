<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

api_giris_gerekli();
$kullanici = aktif_kullanici($pdo);
if (!$kullanici) {
    http_response_code(401);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Oturum doğrulanamadı.'));
    exit;
}

$kullanici_id = (int) $kullanici['id'];
$metot = $_SERVER['REQUEST_METHOD'];

if ($metot === 'GET') {
    $stmt = $pdo->prepare('
        SELECT id, tur, mesaj, link, okundu, olusturma_tarihi
        FROM bildirimler
        WHERE kullanici_id = ?
        ORDER BY olusturma_tarihi DESC, id DESC
        LIMIT 15
    ');
    $stmt->execute(array($kullanici_id));
    $kayitlar = $stmt->fetchAll();

    $bildirimler = array();
    foreach ($kayitlar as $satir) {
        $bildirimler[] = array(
            'id' => (int) $satir['id'],
            'tur' => $satir['tur'],
            'mesaj' => $satir['mesaj'],
            'link' => $satir['link'],
            'okundu' => ((int) $satir['okundu']) === 1,
            'tarih' => tarih_format($satir['olusturma_tarihi'], true),
            'olusturma_tarihi' => $satir['olusturma_tarihi'],
        );
    }

    $okunmamis = okunmamis_bildirim_sayisi($pdo, $kullanici_id);

    echo json_encode(array(
        'basarili' => true,
        'okunmamis_sayisi' => $okunmamis,
        'bildirimler' => $bildirimler,
    ));
    exit;
}

if ($metot === 'POST') {
    $veri = $_POST;
    $icerik_tipi = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($icerik_tipi, 'application/json') !== false) {
        $ham = file_get_contents('php://input');
        $cozulen = json_decode($ham, true);
        if (is_array($cozulen)) {
            $veri = $cozulen;
        }
    }

    $token = isset($veri['csrf_token']) ? $veri['csrf_token'] : '';
    if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (!csrf_dogrula($token)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Güvenlik doğrulaması (CSRF) başarısız.'));
        exit;
    }

    $islem = isset($veri['action']) ? trim((string) $veri['action']) : 'okundu';
    if ($islem !== 'okundu') {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz işlem türü.'));
        exit;
    }

    $tumu = (!empty($veri['tumu']) && $veri['tumu'] !== '0' && $veri['tumu'] !== 'false')
        || (isset($veri['id']) && (string) $veri['id'] === 'tumu');

    if ($tumu) {
        $guncelle = $pdo->prepare('UPDATE bildirimler SET okundu = 1 WHERE kullanici_id = ? AND okundu = 0');
        $guncelle->execute(array($kullanici_id));
        echo json_encode(array(
            'basarili' => true,
            'mesaj' => 'Tüm bildirimler okundu olarak işaretlendi.',
            'okunmamis_sayisi' => 0,
        ));
        exit;
    }

    $bildirim_id = isset($veri['id']) ? (int) $veri['id'] : 0;
    if ($bildirim_id <= 0) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz bildirim numarası.'));
        exit;
    }

    // IDOR kontrolu: Bildirimin bu kullaniciya ait oldugunu dogrula
    $kontrol = $pdo->prepare('SELECT id, kullanici_id FROM bildirimler WHERE id = ? LIMIT 1');
    $kontrol->execute(array($bildirim_id));
    $kayit = $kontrol->fetch();
    if (!$kayit) {
        http_response_code(404);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bildirim bulunamadı.'));
        exit;
    }
    if ((int) $kayit['kullanici_id'] !== $kullanici_id) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu bildirimi güncelleme yetkiniz yok.'));
        exit;
    }

    $guncelle = $pdo->prepare('UPDATE bildirimler SET okundu = 1 WHERE id = ? AND kullanici_id = ?');
    $guncelle->execute(array($bildirim_id, $kullanici_id));

    echo json_encode(array(
        'basarili' => true,
        'mesaj' => 'Bildirim okundu olarak işaretlendi.',
        'id' => $bildirim_id,
        'okunmamis_sayisi' => okunmamis_bildirim_sayisi($pdo, $kullanici_id),
    ));
    exit;
}

http_response_code(405);
echo json_encode(array('basarili' => false, 'mesaj' => 'Yalnızca GET ve POST istekleri kabul edilir.'));
