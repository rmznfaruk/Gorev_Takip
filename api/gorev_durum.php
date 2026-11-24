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

$gorev_id = isset($veri['gorev_id']) ? (int) $veri['gorev_id'] : 0;
$durum = isset($veri['durum']) ? trim((string) $veri['durum']) : '';
$gecerli_durumlar = array('yapilacak', 'devam_ediyor', 'tamamlandi');

if ($gorev_id <= 0 || !in_array($durum, $gecerli_durumlar, true)) {
    http_response_code(400);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz görev numarası veya durum değeri.'));
    exit;
}

$stmt = $pdo->prepare('
    SELECT g.id, g.proje_id, g.baslik, g.atanan_id, g.olusturan_id, g.durum, p.yonetici_id
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE g.id = ?
    LIMIT 1
');
$stmt->execute(array($gorev_id));
$gorev = $stmt->fetch();

if (!$gorev) {
    http_response_code(404);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Görev bulunamadı.'));
    exit;
}

$proje_id = (int) $gorev['proje_id'];

// Strict Room Isolation
if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
    http_response_code(403);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
    exit;
}

$kullanici_id = (int) $kullanici['id'];
$proje_ref = array('id' => $proje_id, 'yonetici_id' => $gorev['yonetici_id']);
$yetkili = proje_lider_yetkisi_var_mi($pdo, $proje_ref, $kullanici)
    || ($gorev['atanan_id'] !== null && (int) $gorev['atanan_id'] === $kullanici_id);

if (!$yetkili) {
    http_response_code(403);
    echo json_encode(array('basarili' => false, 'mesaj' => 'Bu görevin durumunu değiştirme yetkiniz yok.'));
    exit;
}

$guncelle = $pdo->prepare('UPDATE gorevler SET durum = ?, guncelleme_tarihi = ? WHERE id = ?');
$guncelle->execute(array($durum, istanbul_simdi(), $gorev_id));

$etiket = durum_etiketi($durum);
aktivite_kaydet(
    $pdo,
    $proje_id,
    $kullanici_id,
    $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevinin durumunu "' . $etiket['metin'] . '" yaptı.'
);

$bildirim_mesaj = $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevinin durumunu "' . $etiket['metin'] . '" olarak güncelledi.';
$bildirim_link = 'gorev_detay.php?id=' . $gorev_id;
if (!empty($gorev['atanan_id'])) {
    bildirim_ekle($pdo, (int) $gorev['atanan_id'], 'gorev_durum', $bildirim_mesaj, $bildirim_link);
}
if (!empty($gorev['olusturan_id']) && (int) $gorev['olusturan_id'] !== (int) $gorev['atanan_id']) {
    bildirim_ekle($pdo, (int) $gorev['olusturan_id'], 'gorev_durum', $bildirim_mesaj, $bildirim_link);
}

$alt_stmt = $pdo->prepare('SELECT COUNT(*) AS toplam, SUM(CASE WHEN tamamlandi = 1 THEN 1 ELSE 0 END) AS tamamlanan FROM alt_gorevler WHERE gorev_id = ?');
$alt_stmt->execute(array($gorev_id));
$alt_ozet = $alt_stmt->fetch();
$alt_toplam = $alt_ozet ? (int) $alt_ozet['toplam'] : 0;
$alt_tamamlanan = ($alt_ozet && $alt_ozet['tamamlanan'] !== null) ? (int) $alt_ozet['tamamlanan'] : 0;
$ilerleme = gorev_ilerleme_hesapla(array('durum' => $durum), $alt_toplam, $alt_tamamlanan);

echo json_encode(array(
    'basarili' => true,
    'mesaj' => 'Görev durumu güncellendi.',
    'gorev_id' => $gorev_id,
    'durum' => $durum,
    'etiket' => $etiket,
    'ilerleme' => $ilerleme,
));
