<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Yalnızca POST isteği kabul edilir.'));
    exit;
}

api_giris_gerekli();
$kullanici = aktif_kullanici($pdo);
if (!$kullanici) {
    http_response_code(401);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Oturum doğrulanamadı.'));
    exit;
}

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

$islem = isset($veri['action']) ? trim((string) $veri['action']) : 'ekle';
$kullanici_id = (int) $kullanici['id'];

if ($islem === 'sil') {
    $yorum_id = isset($veri['yorum_id']) ? (int) $veri['yorum_id'] : 0;
    if ($yorum_id <= 0) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz yorum numarası.'));
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT y.id, y.kullanici_id, g.proje_id, p.yonetici_id
        FROM yorumlar y
        INNER JOIN gorevler g ON g.id = y.gorev_id
        INNER JOIN projeler p ON p.id = g.proje_id
        WHERE y.id = ?
        LIMIT 1
    ');
    $stmt->execute(array($yorum_id));
    $yorum = $stmt->fetch();

    if (!$yorum) {
        http_response_code(404);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Yorum bulunamadı.'));
        exit;
    }

    $proje_id = (int) $yorum['proje_id'];

    // Strict Room Isolation
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
        exit;
    }

    $proje_ref = array('id' => $proje_id, 'yonetici_id' => $yorum['yonetici_id']);
    if ((int) $yorum['kullanici_id'] !== $kullanici_id && !proje_lider_yetkisi_var_mi($pdo, $proje_ref, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu yorumu silme yetkiniz yok.'));
        exit;
    }

    $sil = $pdo->prepare('DELETE FROM yorumlar WHERE id = ?');
    $sil->execute(array($yorum_id));

    echo json_encode(array(
        'basarili' => true,
        'mesaj' => 'Yorum silindi.',
        'yorum_id' => $yorum_id,
    ));
    exit;
}

if ($islem !== 'ekle') {
    http_response_code(400);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz işlem türü.'));
    exit;
}

$gorev_id = isset($veri['gorev_id']) ? (int) $veri['gorev_id'] : 0;
$icerik = isset($veri['icerik']) ? trim((string) $veri['icerik']) : '';

if ($gorev_id <= 0) {
    http_response_code(400);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz görev numarası.'));
    exit;
}

$uzunluk = function_exists('mb_strlen') ? mb_strlen($icerik, 'UTF-8') : strlen($icerik);
if ($uzunluk < 2 || $uzunluk > 2000) {
    http_response_code(400);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Yorum en az 2, en fazla 2000 karakter olmalıdır.'));
    exit;
}

$gorev_kontrol = $pdo->prepare('SELECT id, proje_id, baslik, atanan_id, olusturan_id FROM gorevler WHERE id = ? LIMIT 1');
$gorev_kontrol->execute(array($gorev_id));
$gorev_kayit = $gorev_kontrol->fetch();
if (!$gorev_kayit) {
    http_response_code(404);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Görev bulunamadı.'));
    exit;
}

$proje_id = (int) $gorev_kayit['proje_id'];

// Strict Room Isolation
if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
    http_response_code(403);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
    exit;
}

$ekle = $pdo->prepare('INSERT INTO yorumlar (gorev_id, kullanici_id, icerik, olusturma_tarihi) VALUES (?, ?, ?, ?)');
$ekle->execute(array($gorev_id, $kullanici_id, $icerik, istanbul_simdi()));
$yeni_id = (int) $pdo->lastInsertId();

aktivite_kaydet(
    $pdo,
    $proje_id,
    $kullanici_id,
    $kullanici['ad_soyad'] . ', "' . $gorev_kayit['baslik'] . '" görevine yorum ekledi.'
);

$bildirim_mesaj = $kullanici['ad_soyad'] . ', "' . $gorev_kayit['baslik'] . '" görevine yeni yorum yazdı.';
$bildirim_link = 'gorev_detay.php?id=' . $gorev_id;
if (!empty($gorev_kayit['atanan_id'])) {
    bildirim_ekle($pdo, (int) $gorev_kayit['atanan_id'], 'yorum', $bildirim_mesaj, $bildirim_link);
}
if (!empty($gorev_kayit['olusturan_id']) && (int) $gorev_kayit['olusturan_id'] !== (int) $gorev_kayit['atanan_id']) {
    bildirim_ekle($pdo, (int) $gorev_kayit['olusturan_id'], 'yorum', $bildirim_mesaj, $bildirim_link);
}

$cek = $pdo->prepare('SELECT olusturma_tarihi FROM yorumlar WHERE id = ? LIMIT 1');
$cek->execute(array($yeni_id));
$kayit = $cek->fetch();
$tarih_ham = $kayit ? $kayit['olusturma_tarihi'] : istanbul_simdi();

http_response_code(201);
echo json_encode(array(
    'basarili' => true,
    'mesaj' => 'Yorum eklendi.',
    'yorum' => array(
        'id' => $yeni_id,
        'gorev_id' => $gorev_id,
        'kullanici_id' => $kullanici_id,
        'ad_soyad' => $kullanici['ad_soyad'],
        'unvan' => $kullanici['unvan'],
        'bas_harfler' => bas_harfler($kullanici['ad_soyad']),
        'icerik' => $icerik,
        'tarih' => tarih_format($tarih_ham, true),
        'silebilir_mi' => true,
    ),
));
