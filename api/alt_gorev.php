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

$islem = isset($veri['action']) ? trim((string) $veri['action']) : '';
$kullanici_id = (int) $kullanici['id'];

function alt_gorev_ilerleme_ozeti(PDO $pdo, $gorev_id, $gorev_durum)
{
    return gorev_durumu_alt_gorevlerle_senkronla($pdo, $gorev_id, $gorev_durum);
}

if ($islem === 'ekle') {
    $gorev_id = isset($veri['gorev_id']) ? (int) $veri['gorev_id'] : 0;
    $hafta_etiketi = isset($veri['hafta_etiketi']) ? trim((string) $veri['hafta_etiketi']) : 'Hafta 1';
    $baslik = isset($veri['baslik']) ? trim((string) $veri['baslik']) : '';
    $atanan_id = isset($veri['atanan_id']) && (int) $veri['atanan_id'] > 0 ? (int) $veri['atanan_id'] : null;
    $son_tarih = isset($veri['son_tarih']) && trim((string) $veri['son_tarih']) !== '' ? trim((string) $veri['son_tarih']) : null;

    if ($hafta_etiketi === '') {
        $hafta_etiketi = 'Hafta 1';
    }
    $hafta_etiketi = metin_kisalt($hafta_etiketi, 60);

    if ($gorev_id <= 0) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz görev numarası.'));
        exit;
    }

    $uzunluk = function_exists('mb_strlen') ? mb_strlen($baslik, 'UTF-8') : strlen($baslik);
    if ($uzunluk < 2 || $uzunluk > 220) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görev başlığı en az 2, en fazla 220 karakter olmalıdır.'));
        exit;
    }

    if ($son_tarih !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $son_tarih)) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz son tarih biçimi.'));
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT g.id, g.proje_id, g.baslik, g.durum, g.atanan_id, p.yonetici_id
        FROM gorevler g
        INNER JOIN projeler p ON p.id = g.proje_id
        WHERE g.id = ?
        LIMIT 1
    ');
    $stmt->execute(array($gorev_id));
    $gorev = $stmt->fetch();

    if (!$gorev) {
        http_response_code(404);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Ana görev bulunamadı.'));
        exit;
    }

    $proje_id = (int) $gorev['proje_id'];

    // Siki oda izolasyonu
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
        exit;
    }

    // Yalnizca oda kurucusu, birim lideri veya global yonetici alt gorev ekleyebilir
    $proje_ref = array('id' => $proje_id, 'yonetici_id' => $gorev['yonetici_id']);
    if (!proje_lider_yetkisi_var_mi($pdo, $proje_ref, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görev eklemek için oda lideri veya yönetici yetkisi gerekiyor.'));
        exit;
    }

    // Atanan kisi oda uyesi olmali
    $atanan_adi = null;
    if ($atanan_id !== null) {
        if (!proje_uyesi_mi($pdo, $proje_id, $atanan_id)) {
            http_response_code(403);
            echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görev yalnızca bu odanın üyelerine atanabilir.'));
            exit;
        }
        $u_stmt = $pdo->prepare('SELECT ad_soyad FROM kullanicilar WHERE id = ? LIMIT 1');
        $u_stmt->execute(array($atanan_id));
        $u_kayit = $u_stmt->fetch();
        $atanan_adi = $u_kayit ? $u_kayit['ad_soyad'] : null;
    }

    $sira_stmt = $pdo->prepare('SELECT COALESCE(MAX(sira), 0) + 1 FROM alt_gorevler WHERE gorev_id = ?');
    $sira_stmt->execute(array($gorev_id));
    $yeni_sira = (int) $sira_stmt->fetchColumn();

    $ekle = $pdo->prepare('
        INSERT INTO alt_gorevler (gorev_id, hafta_etiketi, baslik, atanan_id, son_tarih, tamamlandi, sira, olusturma_tarihi)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?)
    ');
    $ekle->execute(array($gorev_id, $hafta_etiketi, $baslik, $atanan_id, $son_tarih, $yeni_sira, istanbul_simdi()));
    $yeni_id = (int) $pdo->lastInsertId();

    if ($atanan_id !== null) {
        bildirim_ekle(
            $pdo,
            $atanan_id,
            'alt_gorev',
            $kullanici['ad_soyad'] . ', "' . $hafta_etiketi . '" planına size alt görev atadı: ' . $baslik,
            'gorev_detay.php?id=' . $gorev_id
        );
    }

    aktivite_kaydet(
        $pdo,
        $proje_id,
        $kullanici_id,
        $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine ' . $hafta_etiketi . ' alt görevi ekledi: ' . $baslik
    );

    $ozet = alt_gorev_ilerleme_ozeti($pdo, $gorev_id, $gorev['durum']);
    $yeni_alt_ref = array('atanan_id' => $atanan_id);
    $silebilir_mi = alt_gorev_silebilir_mi($proje_ref, $kullanici);
    $degistirebilir_mi = alt_gorev_durum_degistirebilir_mi($proje_ref, $gorev, $yeni_alt_ref, $kullanici);

    http_response_code(201);
    echo json_encode(array(
        'basarili' => true,
        'mesaj' => 'Alt görev eklendi.',
        'alt_gorev' => array(
            'id' => $yeni_id,
            'gorev_id' => $gorev_id,
            'hafta_etiketi' => $hafta_etiketi,
            'baslik' => $baslik,
            'atanan_id' => $atanan_id,
            'atanan_adi' => $atanan_adi,
            'atanan_bas_harfler' => $atanan_adi ? bas_harfler($atanan_adi) : null,
            'atanan_avatar_sinif' => $atanan_adi ? avatar_renk_sinifi($atanan_adi) : null,
            'atanan_avatar_varyant' => $atanan_adi ? stickman_avatar_indeks($atanan_adi) : null,
            'atanan_avatar_svg' => $atanan_adi ? stickman_avatar_svg($atanan_adi) : null,
            'son_tarih' => $son_tarih,
            'son_tarih_format' => $son_tarih ? tarih_format($son_tarih) : '-',
            'tamamlandi' => 0,
            'sira' => $yeni_sira,
            'silebilir_mi' => $silebilir_mi,
            'degistirebilir_mi' => $degistirebilir_mi,
        ),
        'alt_toplam' => $ozet['alt_toplam'],
        'alt_tamamlanan' => $ozet['alt_tamamlanan'],
        'ilerleme' => $ozet['ilerleme'],
        'gorev_durum' => $ozet['gorev_durum'],
    ));
    exit;
}

if ($islem === 'durum') {
    $alt_id = isset($veri['alt_gorev_id']) ? (int) $veri['alt_gorev_id'] : (isset($veri['id']) ? (int) $veri['id'] : 0);
    if ($alt_id <= 0) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz alt görev numarası.'));
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT ag.id, ag.gorev_id, ag.hafta_etiketi, ag.baslik, ag.atanan_id, ag.tamamlandi,
               g.proje_id, g.baslik AS gorev_baslik, g.durum AS gorev_durum, g.atanan_id AS gorev_atanan_id, g.olusturan_id,
               p.yonetici_id
        FROM alt_gorevler ag
        INNER JOIN gorevler g ON g.id = ag.gorev_id
        INNER JOIN projeler p ON p.id = g.proje_id
        WHERE ag.id = ?
        LIMIT 1
    ');
    $stmt->execute(array($alt_id));
    $alt = $stmt->fetch();

    if (!$alt) {
        http_response_code(404);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görev bulunamadı.'));
        exit;
    }

    $proje_id = (int) $alt['proje_id'];

    // Siki oda izolasyonu
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
        exit;
    }

    $proje_ref = array('id' => $proje_id, 'yonetici_id' => $alt['yonetici_id']);
    $gorev_ref = array('atanan_id' => $alt['gorev_atanan_id']);
    $yetkili = alt_gorev_durum_degistirebilir_mi($proje_ref, $gorev_ref, $alt, $kullanici);

    if (!$yetkili) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu alt görevin durumunu yalnızca atanan kişi veya ekip lideri değiştirebilir.'));
        exit;
    }

    if (isset($veri['tamamlandi'])) {
        $yeni_durum = ((int) $veri['tamamlandi'] === 1 || $veri['tamamlandi'] === true || $veri['tamamlandi'] === 'true') ? 1 : 0;
    } else {
        $yeni_durum = ((int) $alt['tamamlandi'] === 1) ? 0 : 1;
    }

    $guncelle = $pdo->prepare('UPDATE alt_gorevler SET tamamlandi = ? WHERE id = ?');
    $guncelle->execute(array($yeni_durum, $alt_id));

    $durum_metni = $yeni_durum === 1 ? 'tamamladı' : 'yeniden açtı';
    aktivite_kaydet(
        $pdo,
        $proje_id,
        $kullanici_id,
        $kullanici['ad_soyad'] . ', "' . $alt['baslik'] . '" alt görevini ' . $durum_metni . '.'
    );

    if ($yeni_durum === 1 && !empty($alt['olusturan_id'])) {
        bildirim_ekle(
            $pdo,
            (int) $alt['olusturan_id'],
            'alt_gorev_tamamlandi',
            $kullanici['ad_soyad'] . ', "' . $alt['baslik'] . '" alt görevini tamamladı.',
            'gorev_detay.php?id=' . (int) $alt['gorev_id']
        );
    }

    $ozet = alt_gorev_ilerleme_ozeti($pdo, (int) $alt['gorev_id'], $alt['gorev_durum']);

    echo json_encode(array(
        'basarili' => true,
        'mesaj' => $yeni_durum === 1 ? 'Alt görev tamamlandı.' : 'Alt görev tekrar açıldı.',
        'alt_gorev_id' => $alt_id,
        'tamamlandi' => $yeni_durum,
        'alt_toplam' => $ozet['alt_toplam'],
        'alt_tamamlanan' => $ozet['alt_tamamlanan'],
        'ilerleme' => $ozet['ilerleme'],
        'gorev_durum' => $ozet['gorev_durum'],
    ));
    exit;
}

if ($islem === 'sil') {
    $alt_id = isset($veri['alt_gorev_id']) ? (int) $veri['alt_gorev_id'] : (isset($veri['id']) ? (int) $veri['id'] : 0);
    if ($alt_id <= 0) {
        http_response_code(400);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz alt görev numarası.'));
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT ag.id, ag.gorev_id, ag.baslik, g.proje_id, g.durum AS gorev_durum, p.yonetici_id
        FROM alt_gorevler ag
        INNER JOIN gorevler g ON g.id = ag.gorev_id
        INNER JOIN projeler p ON p.id = g.proje_id
        WHERE ag.id = ?
        LIMIT 1
    ');
    $stmt->execute(array($alt_id));
    $alt = $stmt->fetch();

    if (!$alt) {
        http_response_code(404);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görev bulunamadı.'));
        exit;
    }

    $proje_id = (int) $alt['proje_id'];

    // Siki oda izolasyonu
    if (!proje_erisim_var_mi($pdo, $proje_id, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Bu odaya erişim yetkiniz bulunmuyor.'));
        exit;
    }

    $proje_ref = array('id' => $proje_id, 'yonetici_id' => $alt['yonetici_id']);
    if (!alt_gorev_silebilir_mi($proje_ref, $kullanici)) {
        http_response_code(403);
        echo json_encode(array('basarili' => false, 'mesaj' => 'Alt görevleri yalnızca odanın ekip lideri silebilir.'));
        exit;
    }

    $sil = $pdo->prepare('DELETE FROM alt_gorevler WHERE id = ?');
    $sil->execute(array($alt_id));

    aktivite_kaydet(
        $pdo,
        $proje_id,
        $kullanici_id,
        $kullanici['ad_soyad'] . ', "' . $alt['baslik'] . '" alt görevini sildi.'
    );

    $ozet = alt_gorev_ilerleme_ozeti($pdo, (int) $alt['gorev_id'], $alt['gorev_durum']);

    echo json_encode(array(
        'basarili' => true,
        'mesaj' => 'Alt görev silindi.',
        'alt_gorev_id' => $alt_id,
        'alt_toplam' => $ozet['alt_toplam'],
        'alt_tamamlanan' => $ozet['alt_tamamlanan'],
        'ilerleme' => $ozet['ilerleme'],
        'gorev_durum' => $ozet['gorev_durum'],
    ));
    exit;
}

http_response_code(400);
echo json_encode(array('basarili' => false, 'mesaj' => 'Geçersiz işlem türü.'));
