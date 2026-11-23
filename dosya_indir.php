<?php
/**
 * Guvenli gorev dosyasi indirme / goruntuleme uc noktasi.
 * Yalnizca giris yapmis kullanicilar erisebilir; path traversal engellenir.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);

$dosya_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($dosya_id <= 0) {
    http_response_code(404);
    exit('Dosya bulunamadı.');
}

$stmt = $pdo->prepare("
    SELECT d.id, d.gorev_id, d.baslik, d.dosya_yolu, d.mime_tipi, g.proje_id
    FROM gorev_dosyalari d
    INNER JOIN gorevler g ON g.id = d.gorev_id
    WHERE d.id = ? AND d.tur = 'dosya'
    LIMIT 1
");
$stmt->execute(array($dosya_id));
$kayit = $stmt->fetch();

if (!$kayit || empty($kayit['dosya_yolu'])) {
    http_response_code(404);
    exit('Dosya bulunamadı.');
}

// Strict Room Isolation: Kullanici yalnizca uyesi/yoneticisi oldugu odanin dosyalarini indirebilir.
if (!proje_erisim_var_mi($pdo, (int) $kayit['proje_id'], $kullanici)) {
    http_response_code(403);
    exit('Bu dosyaya erişim yetkiniz bulunmuyor.');
}

$guvenli_ad = basename((string) $kayit['dosya_yolu']);
$upload_kok = realpath(UPLOAD_DIR);

if ($upload_kok === false || $guvenli_ad === '') {
    http_response_code(404);
    exit('Dosya sunucuda bulunamadı.');
}

$tam_yol = realpath($upload_kok . DIRECTORY_SEPARATOR . $guvenli_ad);
if ($tam_yol === false || !is_file($tam_yol) || strpos($tam_yol, $upload_kok . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Dosya sunucuda bulunamadı.');
}

$uzanti = strtolower(pathinfo($guvenli_ad, PATHINFO_EXTENSION));
$izinli_mime = array(
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
);

if (isset($izinli_mime[$uzanti])) {
    $mime = $izinli_mime[$uzanti];
    $disposition = 'inline';
} else {
    $mime = 'application/octet-stream';
    $disposition = 'attachment';
}

$indirme_adi = preg_replace('/[^\p{L}\p{N}\s\.\-_]/u', '_', (string) $kayit['baslik']);
$indirme_adi = trim(preg_replace('/\s+/', ' ', (string) $indirme_adi));
if ($indirme_adi === '') {
    $indirme_adi = 'belge_' . (int) $kayit['id'];
}
if ($uzanti !== '' && strtolower(pathinfo($indirme_adi, PATHINFO_EXTENSION)) !== $uzanti) {
    $indirme_adi .= '.' . $uzanti;
}
$indirme_adi = str_replace(array('"', "\r", "\n"), '', $indirme_adi);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($tam_yol));
header('Content-Disposition: ' . $disposition . '; filename="' . $indirme_adi . '"');

readfile($tam_yol);
exit;
