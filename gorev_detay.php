<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$csrf = csrf_token();

$gorev_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($gorev_id <= 0 && isset($_POST['gorev_id'])) {
    $gorev_id = (int) $_POST['gorev_id'];
}

$stmt = $pdo->prepare('
    SELECT g.*, p.ad AS proje_adi, p.kod AS proje_kodu, p.yonetici_id AS proje_yonetici_id,
           u_atanan.ad_soyad AS atanan_adi, u_atanan.unvan AS atanan_unvan,
           u_olusturan.ad_soyad AS olusturan_adi
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u_atanan ON u_atanan.id = g.atanan_id
    INNER JOIN kullanicilar u_olusturan ON u_olusturan.id = g.olusturan_id
    WHERE g.id = ?
    LIMIT 1
');
$stmt->execute(array($gorev_id));
$gorev = $stmt->fetch();

if (!$gorev) {
    flash_ekle('error', 'Aradığınız görev bulunamadı.');
    yonlendir('gorevler.php');
}

// Strict Room Isolation: Yalnizca gorevin ait oldugu odanin yoneticisi veya uyeleri erisebilir.
if (!proje_erisim_var_mi($pdo, (int) $gorev['proje_id'], $kullanici)) {
    flash_ekle('error', 'Bu göreve erişim yetkiniz bulunmuyor.');
    yonlendir('projeler.php');
}

$birimler = birim_secenekleri();
$proje_ref = array('id' => (int) $gorev['proje_id'], 'yonetici_id' => $gorev['proje_yonetici_id']);
$proje_kurucu_yetkisi = proje_yonetebilir_mi($proje_ref, $kullanici);
$proje_yonetebilir = proje_lider_yetkisi_var_mi($pdo, $proje_ref, $kullanici);
$durum_degistirebilir = $proje_yonetebilir
    || ($gorev['atanan_id'] !== null && (int) $gorev['atanan_id'] === $kullanici_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_dogrula($token)) {
        flash_ekle('error', 'Güvenlik doğrulaması başarısız.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    $islem = isset($_POST['islem']) ? trim((string) $_POST['islem']) : '';
    if ($islem === '' && isset($_POST['action'])) {
        $islem = trim((string) $_POST['action']);
    }

    if ($islem === 'gorev_guncelle') {
        if (!$proje_yonetebilir) {
            flash_ekle('error', 'Bu görevi düzenleme yetkiniz yok.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $baslik = isset($_POST['baslik']) ? trim((string) $_POST['baslik']) : '';
        $aciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';
        $atanan_id = isset($_POST['atanan_id']) && (int) $_POST['atanan_id'] > 0 ? (int) $_POST['atanan_id'] : null;
        $durum = isset($_POST['durum']) ? (string) $_POST['durum'] : $gorev['durum'];
        $oncelik = isset($_POST['oncelik']) ? (string) $_POST['oncelik'] : $gorev['oncelik'];
        $birim = isset($_POST['birim']) ? strtolower(trim((string) $_POST['birim'])) : (isset($gorev['birim']) ? $gorev['birim'] : 'genel');
        $son_tarih = isset($_POST['son_tarih']) && $_POST['son_tarih'] !== '' ? $_POST['son_tarih'] : null;

        if (!in_array($durum, array('yapilacak', 'devam_ediyor', 'tamamlandi'), true)) {
            $durum = $gorev['durum'];
        }
        if (!in_array($oncelik, array('dusuk', 'orta', 'yuksek'), true)) {
            $oncelik = $gorev['oncelik'];
        }
        if (!isset($birimler[$birim])) {
            $birim = isset($gorev['birim']) && isset($birimler[$gorev['birim']]) ? $gorev['birim'] : 'genel';
        }
        if ($baslik === '') {
            flash_ekle('error', 'Görev başlığı boş bırakılamaz.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        // Guvenlik: Atanan kisi yalnizca bu gorevin odasindaki uyelerden biri olabilir.
        if ($atanan_id !== null && !proje_uyesi_mi($pdo, (int) $gorev['proje_id'], $atanan_id)) {
            flash_ekle('error', 'Görev yalnızca bu grubun üyelerine atanabilir.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $guncelle = $pdo->prepare('
            UPDATE gorevler
            SET baslik = ?, aciklama = ?, atanan_id = ?, durum = ?, oncelik = ?, birim = ?, son_tarih = ?, guncelleme_tarihi = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $guncelle->execute(array($baslik, $aciklama, $atanan_id, $durum, $oncelik, $birim, $son_tarih, $gorev_id));

        aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $baslik . '" görevini güncelledi.');
        if ($atanan_id !== null && (int) $gorev['atanan_id'] !== $atanan_id) {
            bildirim_ekle($pdo, $atanan_id, 'gorev_atama', $kullanici['ad_soyad'] . ' size görev atadı: ' . $baslik, 'gorev_detay.php?id=' . $gorev_id);
        }

        flash_ekle('success', 'Görev bilgileri güncellendi.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'gorev_sil') {
        if (!$proje_yonetebilir) {
            flash_ekle('error', 'Bu görevi silme yetkiniz yok.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        // Goreve ait fiziksel dosyalari temizle
        $dosya_stmt = $pdo->prepare("SELECT dosya_yolu FROM gorev_dosyalari WHERE gorev_id = ? AND tur = 'dosya'");
        $dosya_stmt->execute(array($gorev_id));
        $upload_kok = realpath(UPLOAD_DIR);
        if ($upload_kok !== false) {
            foreach ($dosya_stmt->fetchAll() as $d_satir) {
                if (!empty($d_satir['dosya_yolu'])) {
                    $tam_yol = realpath($upload_kok . DIRECTORY_SEPARATOR . basename((string) $d_satir['dosya_yolu']));
                    if ($tam_yol !== false && is_file($tam_yol) && strpos($tam_yol, $upload_kok . DIRECTORY_SEPARATOR) === 0) {
                        @unlink($tam_yol);
                    }
                }
            }
        }

        $proje_id_yonlendir = (int) $gorev['proje_id'];
        $sil = $pdo->prepare('DELETE FROM gorevler WHERE id = ?');
        $sil->execute(array($gorev_id));

        flash_ekle('success', 'Görev silindi.');
        yonlendir('proje_detay.php?id=' . $proje_id_yonlendir);
    }

    if ($islem === 'durum_guncelle') {
        if (!$durum_degistirebilir) {
            flash_ekle('error', 'Bu görevin durumunu değiştirme yetkiniz yok.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $yeni_durum = isset($_POST['durum']) ? $_POST['durum'] : '';
        if (in_array($yeni_durum, array('yapilacak', 'devam_ediyor', 'tamamlandi'), true)) {
            $guncelle = $pdo->prepare('UPDATE gorevler SET durum = ?, guncelleme_tarihi = CURRENT_TIMESTAMP WHERE id = ?');
            $guncelle->execute(array($yeni_durum, $gorev_id));
            $etiket = durum_etiketi($yeni_durum);
            aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevinin durumunu "' . $etiket['metin'] . '" yaptı.');
            if (!empty($gorev['atanan_id'])) {
                bildirim_ekle($pdo, (int) $gorev['atanan_id'], 'gorev_durum', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevinin durumunu "' . $etiket['metin'] . '" yaptı.', 'gorev_detay.php?id=' . $gorev_id);
            }
            if (!empty($gorev['olusturan_id']) && (int) $gorev['olusturan_id'] !== (int) $gorev['atanan_id']) {
                bildirim_ekle($pdo, (int) $gorev['olusturan_id'], 'gorev_durum', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevinin durumunu "' . $etiket['metin'] . '" yaptı.', 'gorev_detay.php?id=' . $gorev_id);
            }
            flash_ekle('success', 'Görev durumu güncellendi.');
        }
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'alt_gorev_ekle') {
        if (!$proje_yonetebilir) {
            flash_ekle('error', 'Alt görev eklemek için oda lideri veya yönetici yetkisi gerekiyor.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }
        $hafta_etiketi = isset($_POST['hafta_etiketi']) ? trim((string) $_POST['hafta_etiketi']) : 'Hafta 1';
        if ($hafta_etiketi === '') {
            $hafta_etiketi = 'Hafta 1';
        }
        $hafta_etiketi = metin_kisalt($hafta_etiketi, 60);
        $alt_baslik = isset($_POST['baslik']) ? trim((string) $_POST['baslik']) : '';
        $alt_atanan = isset($_POST['atanan_id']) && (int) $_POST['atanan_id'] > 0 ? (int) $_POST['atanan_id'] : null;
        $alt_son_tarih = isset($_POST['son_tarih']) && trim((string) $_POST['son_tarih']) !== '' ? trim((string) $_POST['son_tarih']) : null;

        $uzunluk = function_exists('mb_strlen') ? mb_strlen($alt_baslik, 'UTF-8') : strlen($alt_baslik);
        if ($uzunluk < 2 || $uzunluk > 220) {
            flash_ekle('error', 'Alt görev başlığı en az 2, en fazla 220 karakter olmalıdır.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }
        if ($alt_atanan !== null && !proje_uyesi_mi($pdo, (int) $gorev['proje_id'], $alt_atanan)) {
            flash_ekle('error', 'Alt görev yalnızca bu odanın üyelerine atanabilir.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $sira_stmt = $pdo->prepare('SELECT COALESCE(MAX(sira), 0) + 1 FROM alt_gorevler WHERE gorev_id = ?');
        $sira_stmt->execute(array($gorev_id));
        $yeni_sira = (int) $sira_stmt->fetchColumn();

        $ekle = $pdo->prepare('
            INSERT INTO alt_gorevler (gorev_id, hafta_etiketi, baslik, atanan_id, son_tarih, tamamlandi, sira)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ');
        $ekle->execute(array($gorev_id, $hafta_etiketi, $alt_baslik, $alt_atanan, $alt_son_tarih, $yeni_sira));

        if ($alt_atanan !== null) {
            bildirim_ekle($pdo, $alt_atanan, 'alt_gorev', $kullanici['ad_soyad'] . ', "' . $hafta_etiketi . '" planına size alt görev atadı: ' . $alt_baslik, 'gorev_detay.php?id=' . $gorev_id);
        }
        aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine ' . $hafta_etiketi . ' alt görevi ekledi: ' . $alt_baslik);
        gorev_durumu_alt_gorevlerle_senkronla($pdo, $gorev_id, $gorev['durum']);

        flash_ekle('success', 'Alt görev eklendi.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'alt_gorev_durum') {
        $alt_id = isset($_POST['alt_gorev_id']) ? (int) $_POST['alt_gorev_id'] : 0;
        $alt_stmt = $pdo->prepare('SELECT id, baslik, atanan_id, tamamlandi FROM alt_gorevler WHERE id = ? AND gorev_id = ? LIMIT 1');
        $alt_stmt->execute(array($alt_id, $gorev_id));
        $alt_kayit = $alt_stmt->fetch();
        if ($alt_kayit) {
            $alt_yetkili = $durum_degistirebilir || ($alt_kayit['atanan_id'] !== null && (int) $alt_kayit['atanan_id'] === $kullanici_id);
            if ($alt_yetkili) {
                $yeni_tamam = ((int) $alt_kayit['tamamlandi'] === 1) ? 0 : 1;
                $guncelle = $pdo->prepare('UPDATE alt_gorevler SET tamamlandi = ? WHERE id = ?');
                $guncelle->execute(array($yeni_tamam, $alt_id));
                aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $alt_kayit['baslik'] . '" alt görevini ' . ($yeni_tamam === 1 ? 'tamamladı.' : 'yeniden açtı.'));
                gorev_durumu_alt_gorevlerle_senkronla($pdo, $gorev_id, $gorev['durum']);
            }
        }
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'alt_gorev_sil') {
        if (!$proje_yonetebilir) {
            flash_ekle('error', 'Alt görev silme yetkiniz yok.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }
        $alt_id = isset($_POST['alt_gorev_id']) ? (int) $_POST['alt_gorev_id'] : 0;
        if ($alt_id > 0) {
            $sil = $pdo->prepare('DELETE FROM alt_gorevler WHERE id = ? AND gorev_id = ?');
            $sil->execute(array($alt_id, $gorev_id));
            gorev_durumu_alt_gorevlerle_senkronla($pdo, $gorev_id, $gorev['durum']);
            flash_ekle('success', 'Alt görev silindi.');
        }
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'yorum_ekle') {
        $icerik = isset($_POST['icerik']) ? trim($_POST['icerik']) : '';
        $uzunluk = function_exists('mb_strlen') ? mb_strlen($icerik, 'UTF-8') : strlen($icerik);
        if ($uzunluk >= 2 && $uzunluk <= 2000) {
            $ekle = $pdo->prepare('INSERT INTO yorumlar (gorev_id, kullanici_id, icerik) VALUES (?, ?, ?)');
            $ekle->execute(array($gorev_id, $kullanici_id, $icerik));
            aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine yorum ekledi.');
            if (!empty($gorev['atanan_id'])) {
                bildirim_ekle($pdo, (int) $gorev['atanan_id'], 'yorum', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine yorum yazdı.', 'gorev_detay.php?id=' . $gorev_id);
            }
            if (!empty($gorev['olusturan_id']) && (int) $gorev['olusturan_id'] !== (int) $gorev['atanan_id']) {
                bildirim_ekle($pdo, (int) $gorev['olusturan_id'], 'yorum', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine yorum yazdı.', 'gorev_detay.php?id=' . $gorev_id);
            }
            flash_ekle('success', 'Yorum eklendi.');
        } else {
            flash_ekle('error', 'Yorum en az 2, en fazla 2000 karakter olmalıdır.');
        }
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'dosya_ekle') {
        if (!isset($_FILES['dosya']) || !is_array($_FILES['dosya'])) {
            flash_ekle('error', 'Lütfen yüklemek için bir dosya seçin.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $hata_kodu = isset($_FILES['dosya']['error']) ? (int) $_FILES['dosya']['error'] : UPLOAD_ERR_NO_FILE;
        if ($hata_kodu !== UPLOAD_ERR_OK) {
            if ($hata_kodu === UPLOAD_ERR_INI_SIZE || $hata_kodu === UPLOAD_ERR_FORM_SIZE) {
                flash_ekle('error', 'Dosya boyutu izin verilen 8 MB sınırını aşıyor.');
            } elseif ($hata_kodu === UPLOAD_ERR_NO_FILE) {
                flash_ekle('error', 'Lütfen yüklemek için bir dosya seçin.');
            } else {
                flash_ekle('error', 'Dosya yüklenirken bir hata oluştu. Lütfen tekrar deneyin.');
            }
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $tmp_yol = isset($_FILES['dosya']['tmp_name']) ? (string) $_FILES['dosya']['tmp_name'] : '';
        $orijinal_ad = isset($_FILES['dosya']['name']) ? (string) $_FILES['dosya']['name'] : '';
        $boyut = isset($_FILES['dosya']['size']) ? (int) $_FILES['dosya']['size'] : 0;

        if ($tmp_yol === '' || !is_uploaded_file($tmp_yol)) {
            flash_ekle('error', 'Geçersiz dosya yükleme isteği.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $gercek_boyut = (int) @filesize($tmp_yol);
        if ($boyut <= 0 || $gercek_boyut <= 0 || $boyut > MAX_DOSYA_BOYUTU || $gercek_boyut > MAX_DOSYA_BOYUTU) {
            flash_ekle('error', 'Dosya boyutu en fazla 8 MB olabilir ve boş olamaz.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $uzanti = strtolower(pathinfo($orijinal_ad, PATHINFO_EXTENSION));
        $izinli_uzantilar = array('pdf', 'png', 'jpg', 'jpeg');
        if (!in_array($uzanti, $izinli_uzantilar, true)) {
            flash_ekle('error', 'Yalnızca PDF, PNG, JPG ve JPEG dosyaları yüklenebilir.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        // Sunucu tarafi magic-byte dogrulamasi (fileinfo kapali olsa bile calisir)
        $fh = @fopen($tmp_yol, 'rb');
        $baslik_bayt = ($fh !== false) ? fread($fh, 16) : '';
        if ($fh !== false) {
            fclose($fh);
        }

        $mime_tipi = '';
        if ($uzanti === 'pdf' && strncmp((string) $baslik_bayt, '%PDF-', 5) === 0) {
            $mime_tipi = 'application/pdf';
        } elseif ($uzanti === 'png' && strncmp((string) $baslik_bayt, "\x89PNG\r\n\x1a\n", 8) === 0) {
            $mime_tipi = 'image/png';
        } elseif (($uzanti === 'jpg' || $uzanti === 'jpeg') && strncmp((string) $baslik_bayt, "\xFF\xD8\xFF", 3) === 0) {
            $mime_tipi = 'image/jpeg';
        }

        if ($mime_tipi === '') {
            flash_ekle('error', 'Dosya içeriği geçerli bir PDF veya görsel formatında değil.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0755, true);
        }

        $guvenli_dosya_adi = bin2hex(random_bytes(16)) . '.' . $uzanti;
        $hedef_yol = UPLOAD_DIR . DIRECTORY_SEPARATOR . $guvenli_dosya_adi;

        if (!move_uploaded_file($tmp_yol, $hedef_yol)) {
            flash_ekle('error', 'Dosya sunucuya kaydedilemedi. Lütfen klasör izinlerini kontrol edin.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $baslik = isset($_POST['baslik']) ? trim((string) $_POST['baslik']) : '';
        if ($baslik === '') {
            $baslik = basename($orijinal_ad);
        }
        if (function_exists('mb_substr')) {
            $baslik = mb_substr($baslik, 0, 200, 'UTF-8');
        } else {
            $baslik = substr($baslik, 0, 200);
        }

        $ekle = $pdo->prepare("
            INSERT INTO gorev_dosyalari (gorev_id, kullanici_id, tur, baslik, dosya_yolu, harici_url, dosya_boyutu, mime_tipi)
            VALUES (?, ?, 'dosya', ?, ?, NULL, ?, ?)
        ");
        $ekle->execute(array($gorev_id, $kullanici_id, $baslik, $guvenli_dosya_adi, $gercek_boyut, $mime_tipi));

        aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine belge yükledi: ' . $baslik);
        if (!empty($gorev['atanan_id'])) {
            bildirim_ekle($pdo, (int) $gorev['atanan_id'], 'dosya', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine dosya yükledi: ' . $baslik, 'gorev_detay.php?id=' . $gorev_id);
        }
        if (!empty($gorev['olusturan_id']) && (int) $gorev['olusturan_id'] !== (int) $gorev['atanan_id']) {
            bildirim_ekle($pdo, (int) $gorev['olusturan_id'], 'dosya', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine dosya yükledi: ' . $baslik, 'gorev_detay.php?id=' . $gorev_id);
        }

        flash_ekle('success', 'Dosya başarıyla yüklendi.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'link_ekle') {
        $baslik = isset($_POST['baslik']) ? trim((string) $_POST['baslik']) : '';
        $harici_url = isset($_POST['harici_url']) ? trim((string) $_POST['harici_url']) : '';

        $url_gecerli = false;
        if ($harici_url !== '' && strlen($harici_url) <= 500 && filter_var($harici_url, FILTER_VALIDATE_URL) !== false) {
            $parcalar = parse_url($harici_url);
            $sema = isset($parcalar['scheme']) ? strtolower((string) $parcalar['scheme']) : '';
            if (in_array($sema, array('http', 'https'), true) && !empty($parcalar['host'])) {
                $url_gecerli = true;
            }
        }

        if (!$url_gecerli) {
            flash_ekle('error', 'Lütfen yalnızca http:// veya https:// ile başlayan geçerli bir bağlantı girin.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        if ($baslik === '') {
            $parcalar = parse_url($harici_url);
            $baslik = isset($parcalar['host']) ? (string) $parcalar['host'] : 'Harici Bağlantı';
        }
        if (function_exists('mb_substr')) {
            $baslik = mb_substr($baslik, 0, 200, 'UTF-8');
        } else {
            $baslik = substr($baslik, 0, 200);
        }

        $ekle = $pdo->prepare("
            INSERT INTO gorev_dosyalari (gorev_id, kullanici_id, tur, baslik, dosya_yolu, harici_url, dosya_boyutu, mime_tipi)
            VALUES (?, ?, 'link', ?, NULL, ?, 0, NULL)
        ");
        $ekle->execute(array($gorev_id, $kullanici_id, $baslik, $harici_url));

        aktivite_kaydet($pdo, (int) $gorev['proje_id'], $kullanici_id, $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine bağlantı ekledi: ' . $baslik);
        if (!empty($gorev['atanan_id'])) {
            bildirim_ekle($pdo, (int) $gorev['atanan_id'], 'dosya', $kullanici['ad_soyad'] . ', "' . $gorev['baslik'] . '" görevine bağlantı ekledi: ' . $baslik, 'gorev_detay.php?id=' . $gorev_id);
        }

        flash_ekle('success', 'Harici bağlantı eklendi.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }

    if ($islem === 'dosya_sil') {
        $dosya_id = isset($_POST['dosya_id']) ? (int) $_POST['dosya_id'] : 0;
        if ($dosya_id <= 0) {
            flash_ekle('error', 'Geçersiz dosya veya bağlantı seçimi.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $kontrol = $pdo->prepare('SELECT id, kullanici_id, tur, dosya_yolu FROM gorev_dosyalari WHERE id = ? AND gorev_id = ? LIMIT 1');
        $kontrol->execute(array($dosya_id, $gorev_id));
        $kayit = $kontrol->fetch();

        if (!$kayit) {
            flash_ekle('error', 'Silinecek kayıt bulunamadı.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        $silebilir_mi = ((int) $kayit['kullanici_id'] === $kullanici_id) || $proje_yonetebilir;
        if (!$silebilir_mi) {
            flash_ekle('error', 'Bu eki silme yetkiniz yok.');
            yonlendir('gorev_detay.php?id=' . $gorev_id);
        }

        if ($kayit['tur'] === 'dosya' && !empty($kayit['dosya_yolu'])) {
            $upload_kok = realpath(UPLOAD_DIR);
            if ($upload_kok !== false) {
                $tam_yol = realpath($upload_kok . DIRECTORY_SEPARATOR . basename((string) $kayit['dosya_yolu']));
                if ($tam_yol !== false && is_file($tam_yol) && strpos($tam_yol, $upload_kok . DIRECTORY_SEPARATOR) === 0) {
                    @unlink($tam_yol);
                }
            }
        }

        $sil = $pdo->prepare('DELETE FROM gorev_dosyalari WHERE id = ? AND gorev_id = ?');
        $sil->execute(array($dosya_id, $gorev_id));

        flash_ekle('success', $kayit['tur'] === 'dosya' ? 'Dosya silindi.' : 'Bağlantı silindi.');
        yonlendir('gorev_detay.php?id=' . $gorev_id);
    }
}

// Haftalik alt gorevler (Checklist)
$stmt = $pdo->prepare('
    SELECT ag.id, ag.gorev_id, ag.hafta_etiketi, ag.baslik, ag.atanan_id,
           ag.son_tarih, ag.tamamlandi, ag.sira, ag.olusturma_tarihi,
           u.ad_soyad AS atanan_adi
    FROM alt_gorevler ag
    LEFT JOIN kullanicilar u ON u.id = ag.atanan_id
    WHERE ag.gorev_id = ?
    ORDER BY ag.sira ASC, ag.id ASC
');
$stmt->execute(array($gorev_id));
$alt_gorevler = $stmt->fetchAll();

$hafta_gruplari = array();
$alt_toplam = count($alt_gorevler);
$alt_tamamlanan = 0;
$en_yuksek_hafta = 0;
foreach ($alt_gorevler as $alt_item) {
    if ((int) $alt_item['tamamlandi'] === 1) {
        $alt_tamamlanan++;
    }
    $etiket_key = trim((string) $alt_item['hafta_etiketi']);
    if ($etiket_key === '') {
        $etiket_key = 'Hafta 1';
    }
    if (preg_match('/^Hafta\s*(\d+)$/iu', $etiket_key, $eslesme)) {
        $h_no = (int) $eslesme[1];
        if ($h_no > $en_yuksek_hafta) {
            $en_yuksek_hafta = $h_no;
        }
    }
    if (!isset($hafta_gruplari[$etiket_key])) {
        $hafta_gruplari[$etiket_key] = array();
    }
    $hafta_gruplari[$etiket_key][] = $alt_item;
}
$varsayilan_hafta_no = $en_yuksek_hafta > 0 ? min(52, $en_yuksek_hafta + 1) : 1;
if (!empty($hafta_gruplari)) {
    uksort($hafta_gruplari, 'strnatcasecmp');
}

$gorev_ilerleme = gorev_ilerleme_hesapla($gorev, $alt_toplam, $alt_tamamlanan);
$teslim_durumu = teslim_durumu_hesapla($gorev['son_tarih'], $gorev['durum']);

// Ekler ve belgeler
$stmt = $pdo->prepare('
    SELECT d.id, d.gorev_id, d.kullanici_id, d.tur, d.baslik, d.dosya_yolu,
           d.harici_url, d.dosya_boyutu, d.mime_tipi, d.olusturma_tarihi,
           u.ad_soyad AS yukleyen_adi
    FROM gorev_dosyalari d
    INNER JOIN kullanicilar u ON u.id = d.kullanici_id
    WHERE d.gorev_id = ?
    ORDER BY d.olusturma_tarihi DESC, d.id DESC
');
$stmt->execute(array($gorev_id));
$gorev_dosyalari = $stmt->fetchAll();

// Yorumlar
$stmt = $pdo->prepare('
    SELECT y.id, y.gorev_id, y.kullanici_id, y.icerik, y.olusturma_tarihi, u.ad_soyad, u.unvan
    FROM yorumlar y
    INNER JOIN kullanicilar u ON u.id = y.kullanici_id
    WHERE y.gorev_id = ?
    ORDER BY y.olusturma_tarihi ASC
');
$stmt->execute(array($gorev_id));
$yorumlar = $stmt->fetchAll();

// Duzenleme modali icin proje uyeleri
$stmt = $pdo->prepare('
    SELECT u.id, u.ad_soyad, pu.proje_rolu
    FROM proje_uyeleri pu
    INNER JOIN kullanicilar u ON u.id = pu.kullanici_id
    WHERE pu.proje_id = ?
    ORDER BY u.ad_soyad ASC
');
$stmt->execute(array((int) $gorev['proje_id']));
$proje_uyeleri = $stmt->fetchAll();

$durum = durum_etiketi($gorev['durum']);
$oncelik = oncelik_etiketi($gorev['oncelik']);
$gorev_birim_kodu = isset($gorev['birim']) ? $gorev['birim'] : 'genel';
$birim_bilgi = birim_etiketi($gorev_birim_kodu);
$sayfa_basligi = '#' . $gorev['id'] . ' ' . $gorev['baslik'];
$aktif_sayfa = 'gorevler';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="stack-sm">
            <a href="proje_detay.php?id=<?php echo (int) $gorev['proje_id']; ?>" class="btn btn-ghost btn-sm">
                &larr; <?php echo e($gorev['proje_kodu']); ?> Panosu
            </a>
        </div>
        <h1 class="page-title">
            <span class="mono">#<?php echo (int) $gorev['id']; ?></span> <?php echo e($gorev['baslik']); ?>
        </h1>
        <p class="page-subtitle">
            <a href="proje_detay.php?id=<?php echo (int) $gorev['proje_id']; ?>"><?php echo e($gorev['proje_adi']); ?></a>
            &middot; Oluşturan: <?php echo e($gorev['olusturan_adi']); ?>
        </p>
    </div>
    <?php if ($proje_yonetebilir): ?>
        <div class="page-actions">
            <button type="button" class="btn btn-primary" data-modal-open="modal-gorev-duzenle">Düzenle</button>
            <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" data-confirm="Bu görevi silmek istediğinize emin misiniz?">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="islem" value="gorev_sil">
                <button type="submit" class="btn btn-ghost text-danger">Sil</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="stack-lg">
        <section class="card">
            <div class="section-header">
                <h2 class="section-title">Görev Açıklaması</h2>
            </div>
            <div class="comment-text">
                <?php echo $gorev['aciklama'] !== '' && $gorev['aciklama'] !== null ? nl2br(e($gorev['aciklama'])) : 'Bu ana görev için açıklama girilmemiş.'; ?>
            </div>
        </section>

        <section class="card" id="haftalik-plan">
            <div class="section-header">
                <h2 class="section-title">Haftalık Alt Görevler (Hafta 1, Hafta 2...)</h2>
                <span class="mono text-muted" data-subtask-summary><?php echo (int) $alt_tamamlanan; ?>/<?php echo (int) $alt_toplam; ?> tamamlandı</span>
            </div>

            <div class="progress-summary-box" style="margin-bottom:1rem;">
                <div class="progress-summary-head">
                    <span>Haftalık Plan İlerlemesi</span>
                    <span class="progress-summary-pct" data-progress-text>%<?php echo (int) $gorev_ilerleme; ?></span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill<?php echo (int) $gorev_ilerleme >= 100 ? ' is-complete' : ''; ?>"
                         data-progress-bar
                         style="width: <?php echo (int) $gorev_ilerleme; ?>%;"></div>
                </div>
            </div>

            <div class="week-groups" data-week-groups>
                <?php if (empty($hafta_gruplari)): ?>
                    <div class="empty-state">
                        <div class="empty-state-title">Henüz haftalık alt görev eklenmemiş</div>
                        <p>Bu ana görevi <strong>Hafta 1, Hafta 2</strong> şeklinde alt görevlere bölüp ekip üyelerine atayabilirsiniz.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($hafta_gruplari as $hafta_adi => $hafta_ogeleri): ?>
                        <?php
                        $h_toplam = count($hafta_ogeleri);
                        $h_biten = 0;
                        foreach ($hafta_ogeleri as $ho) {
                            if ((int) $ho['tamamlandi'] === 1) {
                                $h_biten++;
                            }
                        }
                        ?>
                        <div class="week-group" data-week-label="<?php echo e($hafta_adi); ?>">
                            <div class="week-group-header">
                                <span class="week-group-title"><?php echo e($hafta_adi); ?></span>
                                <span class="week-group-badge"><?php echo $h_biten; ?>/<?php echo $h_toplam; ?></span>
                            </div>
                            <div class="subtask-list">
                                <?php foreach ($hafta_ogeleri as $alt): ?>
                                    <?php
                                    $tamam_mi = ((int) $alt['tamamlandi'] === 1);
                                    $alt_degistirebilir = $durum_degistirebilir || ($alt['atanan_id'] !== null && (int) $alt['atanan_id'] === $kullanici_id);
                                    ?>
                                    <div class="subtask-item<?php echo $tamam_mi ? ' is-done' : ''; ?>" data-subtask-item="<?php echo (int) $alt['id']; ?>">
                                        <label class="subtask-left">
                                            <input type="checkbox"
                                                   class="subtask-checkbox"
                                                   data-subtask-toggle="<?php echo (int) $alt['id']; ?>"
                                                   data-csrf="<?php echo e($csrf); ?>"
                                                   <?php echo $tamam_mi ? 'checked' : ''; ?>
                                                   <?php echo $alt_degistirebilir ? '' : 'disabled'; ?>>
                                            <span class="subtask-title"><?php echo e($alt['baslik']); ?></span>
                                        </label>
                                        <div class="subtask-right">
                                            <?php if (!empty($alt['atanan_adi'])): ?>
                                                <span class="assignee-cell" title="<?php echo e($alt['atanan_adi']); ?>">
                                                    <span class="avatar-xs" aria-hidden="true"><?php echo e(bas_harfler($alt['atanan_adi'])); ?></span>
                                                    <span><?php echo e($alt['atanan_adi']); ?></span>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($alt['son_tarih'])): ?>
                                                <span class="date-muted"><?php echo e(tarih_format($alt['son_tarih'])); ?></span>
                                            <?php endif; ?>
                                            <?php if ($proje_yonetebilir): ?>
                                                <button type="button"
                                                        class="subtask-delete-btn no-print"
                                                        data-subtask-delete="<?php echo (int) $alt['id']; ?>"
                                                        data-csrf="<?php echo e($csrf); ?>">Sil</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($proje_yonetebilir): ?>
                <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" class="subtask-add-box no-print" data-subtask-form>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="islem" value="alt_gorev_ekle">
                    <input type="hidden" name="gorev_id" value="<?php echo $gorev_id; ?>">
                    <div class="subtask-add-title">+ Haftalık Alt Görev Ekle</div>
                    <div class="subtask-add-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="alt-hafta">Hafta</label>
                            <select id="alt-hafta" name="hafta_etiketi" class="form-control form-control-sm" data-week-select>
                                <?php for ($w = 1; $w <= 52; $w++): ?>
                                    <option value="Hafta <?php echo $w; ?>"<?php echo $w === $varsayilan_hafta_no ? ' selected' : ''; ?>>Hafta <?php echo $w; ?></option>
                                <?php endfor; ?>
                                <option value="__ozel__">Özel Etiket...</option>
                            </select>
                            <input type="text" class="form-control form-control-sm" placeholder="Örn: Sprint 2" data-week-custom hidden style="margin-top:0.35rem;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="alt-baslik">Alt Görev Başlığı</label>
                            <input type="text" id="alt-baslik" name="baslik" class="form-control form-control-sm" maxlength="220" placeholder="Örn: Sensör kalibrasyon testi" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="alt-atanan">Atanan Üye</label>
                            <select id="alt-atanan" name="atanan_id" class="form-control form-control-sm">
                                <option value="">Atanmadı</option>
                                <?php foreach ($proje_uyeleri as $uye): ?>
                                    <option value="<?php echo (int) $uye['id']; ?>"<?php echo (!empty($gorev['atanan_id']) && (int) $gorev['atanan_id'] === (int) $uye['id']) ? ' selected' : ''; ?>><?php echo e($uye['ad_soyad']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="alt-tarih">Son Tarih</label>
                            <input type="date" id="alt-tarih" name="son_tarih" class="form-control form-control-sm">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Ekle</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-header">
                <h2 class="section-title">Ekler ve Belgeler (<?php echo count($gorev_dosyalari); ?>)</h2>
            </div>

            <?php if (empty($gorev_dosyalari)): ?>
                <div class="empty-state">
                    <p>Bu görev için henüz dosya veya harici bağlantı eklenmemiş.</p>
                </div>
            <?php else: ?>
                <div class="attachment-list">
                    <?php foreach ($gorev_dosyalari as $ek): ?>
                        <?php
                        $ek_tur = isset($ek['tur']) ? $ek['tur'] : 'dosya';
                        $ek_silebilir = ((int) $ek['kullanici_id'] === $kullanici_id) || $proje_yonetebilir;
                        $ek_pdf_mi = ($ek_tur === 'dosya' && isset($ek['mime_tipi']) && $ek['mime_tipi'] === 'application/pdf');
                        $ek_ikon_sinif = 'attachment-icon-img';
                        if ($ek_tur === 'link') {
                            $ek_ikon_sinif = 'attachment-icon-link';
                        } elseif ($ek_pdf_mi) {
                            $ek_ikon_sinif = 'attachment-icon-pdf';
                        }
                        $ek_hedef = ($ek_tur === 'link')
                            ? (string) $ek['harici_url']
                            : 'dosya_indir.php?id=' . (int) $ek['id'];
                        ?>
                        <div class="attachment-item">
                            <div class="attachment-main">
                                <span class="attachment-icon <?php echo e($ek_ikon_sinif); ?>" aria-hidden="true">
                                    <?php if ($ek_tur === 'link'): ?>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                        </svg>
                                    <?php elseif ($ek_pdf_mi): ?>
                                        PDF
                                    <?php else: ?>
                                        IMG
                                    <?php endif; ?>
                                </span>
                                <div class="attachment-info">
                                    <div class="attachment-name">
                                        <a href="<?php echo e($ek_hedef); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo e($ek['baslik']); ?>
                                        </a>
                                    </div>
                                    <div class="attachment-meta">
                                        <?php if ($ek_tur === 'dosya'): ?>
                                            <span class="mono"><?php echo e(dosya_boyutu_format($ek['dosya_boyutu'])); ?></span>
                                            <span>&middot;</span>
                                        <?php else: ?>
                                            <span>Harici Bağlantı</span>
                                            <span>&middot;</span>
                                        <?php endif; ?>
                                        <span><?php echo e($ek['yukleyen_adi']); ?></span>
                                        <span>&middot;</span>
                                        <span><?php echo e(tarih_format($ek['olusturma_tarihi'], true)); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="attachment-actions">
                                <?php if ($ek_tur === 'dosya'): ?>
                                    <a href="dosya_indir.php?id=<?php echo (int) $ek['id']; ?>"
                                       class="btn btn-secondary btn-sm"
                                       target="_blank"
                                       rel="noopener noreferrer">Görüntüle / İndir</a>
                                <?php else: ?>
                                    <a href="<?php echo e($ek['harici_url']); ?>"
                                       class="btn btn-secondary btn-sm"
                                       target="_blank"
                                       rel="noopener noreferrer">Bağlantıyı Aç</a>
                                <?php endif; ?>
                                <?php if ($ek_silebilir): ?>
                                    <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" data-confirm="Bu eki silmek istediğinize emin misiniz?">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                        <input type="hidden" name="islem" value="dosya_sil">
                                        <input type="hidden" name="dosya_id" value="<?php echo (int) $ek['id']; ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm text-danger">Sil</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="attachment-forms no-print">
                <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" enctype="multipart/form-data" class="attachment-form-box">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="islem" value="dosya_ekle">
                    <div class="attachment-form-title">PDF / Dosya Yükle (Max 8 MB)</div>
                    <div class="form-group">
                        <label class="form-label" for="ek-dosya-baslik">Belge Başlığı (Opsiyonel)</label>
                        <input type="text" id="ek-dosya-baslik" name="baslik" class="form-control" maxlength="200" placeholder="Örn: Ön Tasarım Raporu">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ek-dosya">Dosya Seç veya Sürükle</label>
                        <div class="dropzone-box" data-dropzone>
                            <input type="file"
                                   id="ek-dosya"
                                   name="dosya"
                                   accept=".pdf,.png,.jpg,.jpeg"
                                   data-file-upload
                                   data-max-size="<?php echo (int) MAX_DOSYA_BOYUTU; ?>"
                                   required>
                            <span class="dropzone-title">Dosya seçmek için tıklayın veya sürükleyin</span>
                            <span class="dropzone-sub">PDF, PNG, JPG veya JPEG &middot; En fazla 8 MB</span>
                            <span class="dropzone-filename"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Dosya Yükle</button>
                </form>

                <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" class="attachment-form-box" data-validate-form>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="islem" value="link_ekle">
                    <div class="attachment-form-title">Bağlantı Ekle (Drive / GitHub)</div>
                    <div class="form-group">
                        <label class="form-label" for="ek-link-baslik">Bağlantı Başlığı (Opsiyonel)</label>
                        <input type="text" id="ek-link-baslik" name="baslik" class="form-control" maxlength="200" placeholder="Örn: GitHub PR veya Drive Klasörü">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ek-harici-url">Bağlantı Adresi (URL)</label>
                        <input type="url" id="ek-harici-url" name="harici_url" class="form-control" maxlength="500" placeholder="https://drive.google.com/..." required>
                        <span class="form-hint">Yalnızca http:// veya https:// adresleri kabul edilir.</span>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Bağlantı Ekle</button>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="section-header">
                <h2 class="section-title">Yorumlar (<span data-comment-count><?php echo count($yorumlar); ?></span>)</h2>
            </div>

            <div class="comment-list" data-comment-list>
                <?php if (empty($yorumlar)): ?>
                    <div class="empty-state">
                        <p>Bu görev için henüz yorum yapılmamış.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($yorumlar as $yorum): ?>
                        <?php $silebilir = ((int) $yorum['kullanici_id'] === $kullanici_id) || $proje_yonetebilir; ?>
                        <div class="comment-item" data-comment-item="<?php echo (int) $yorum['id']; ?>">
                            <div class="avatar"><?php echo e(bas_harfler($yorum['ad_soyad'])); ?></div>
                            <div class="comment-body">
                                <div class="comment-meta">
                                    <div>
                                        <span class="comment-author"><?php echo e($yorum['ad_soyad']); ?></span>
                                        <span class="comment-date"><?php echo e(tarih_format($yorum['olusturma_tarihi'], true)); ?></span>
                                    </div>
                                    <?php if ($silebilir): ?>
                                        <button type="button"
                                                class="btn btn-ghost btn-sm text-danger"
                                                data-comment-delete
                                                data-comment-id="<?php echo (int) $yorum['id']; ?>"
                                                data-csrf="<?php echo e($csrf); ?>">
                                            Sil
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-text"><?php echo nl2br(e($yorum['icerik'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" data-comment-form style="margin-top:1.25rem;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="ekle">
                <input type="hidden" name="islem" value="yorum_ekle">
                <input type="hidden" name="gorev_id" value="<?php echo $gorev_id; ?>">
                <div class="form-group">
                    <label class="form-label" for="yorum-icerik">Yeni Yorum</label>
                    <textarea id="yorum-icerik" name="icerik" class="form-control" rows="3" placeholder="İlerleme notu veya geri bildiriminizi yazın..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Yorum Gönder</button>
            </form>
        </section>
    </div>

    <aside class="stack-md">
        <section class="card">
            <div class="section-header">
                <h2 class="section-title">Görev Detayları</h2>
            </div>

            <div class="progress-summary-box">
                <div class="progress-summary-head">
                    <span>İlerleme Durumu</span>
                    <span class="progress-summary-pct" data-progress-text>%<?php echo (int) $gorev_ilerleme; ?></span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill<?php echo (int) $gorev_ilerleme >= 100 ? ' is-complete' : ''; ?>"
                         data-progress-bar
                         style="width: <?php echo (int) $gorev_ilerleme; ?>%;"></div>
                </div>
            </div>

            <div class="meta-list">
                <div class="meta-row">
                    <span class="meta-key">Durum</span>
                    <?php $durum_sinifi = 'status-' . $gorev['durum']; ?>
                    <?php if ($durum_degistirebilir): ?>
                        <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" style="display:inline-flex;align-items:center;gap:0.4rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                            <input type="hidden" name="islem" value="durum_guncelle">
                            <select id="detay-durum-sec"
                                    name="durum"
                                    class="status-select <?php echo e($durum_sinifi); ?>"
                                    aria-label="Görev durumunu değiştir"
                                    data-task-status
                                    data-task-id="<?php echo $gorev_id; ?>"
                                    data-csrf="<?php echo e($csrf); ?>">
                                <option value="yapilacak"<?php echo $gorev['durum'] === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
                                <option value="devam_ediyor"<?php echo $gorev['durum'] === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
                                <option value="tamamlandi"<?php echo $gorev['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                            </select>
                            <noscript>
                                <button type="submit" class="btn btn-secondary btn-sm">Kaydet</button>
                            </noscript>
                        </form>
                    <?php else: ?>
                        <span class="status-pill <?php echo e($durum_sinifi); ?>" data-task-badge="<?php echo $gorev_id; ?>">
                            <span class="status-dot" aria-hidden="true"></span>
                            <span class="status-pill-text"><?php echo e($durum['metin']); ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Alt Ekip / Birim</span>
                    <span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo e($birim_bilgi['metin']); ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Öncelik</span>
                    <span class="priority-indicator priority-<?php echo e($gorev['oncelik']); ?>">
                        <span class="priority-dot" aria-hidden="true"></span>
                        <span><?php echo e($oncelik['metin']); ?></span>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Proje</span>
                    <a href="proje_detay.php?id=<?php echo (int) $gorev['proje_id']; ?>" class="code-chip"><?php echo e($gorev['proje_kodu']); ?></a>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Atanan</span>
                    <?php if ($gorev['atanan_adi']): ?>
                        <span class="assignee-cell">
                            <span class="avatar-xs" aria-hidden="true"><?php echo e(bas_harfler($gorev['atanan_adi'])); ?></span>
                            <span><?php echo e($gorev['atanan_adi']); ?></span>
                        </span>
                    <?php else: ?>
                        <span class="assignee-unassigned">Atanmadı</span>
                    <?php endif; ?>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Son Tarih</span>
                    <span class="meta-val" style="display:inline-flex;align-items:center;gap:0.45rem;flex-wrap:wrap;justify-content:flex-end;">
                        <span class="date-muted"><?php echo e(tarih_format($gorev['son_tarih'])); ?></span>
                        <?php if (!empty($teslim_durumu['kisa'])): ?>
                            <?php
                            $td_sinif = 'deadline-ok';
                            if ($teslim_durumu['durum'] === 'gecikti') {
                                $td_sinif = 'deadline-overdue';
                            } elseif ($teslim_durumu['durum'] === 'bugun' || $teslim_durumu['durum'] === 'yarin' || $teslim_durumu['durum'] === 'yaklasiyor') {
                                $td_sinif = 'deadline-soon';
                            }
                            ?>
                            <span class="deadline-badge <?php echo $td_sinif; ?>"><?php echo e($teslim_durumu['kisa']); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Oluşturma</span>
                    <span class="meta-val date-muted"><?php echo e(tarih_format($gorev['olusturma_tarihi'], true)); ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-key">Güncelleme</span>
                    <span class="meta-val date-muted"><?php echo e(tarih_format($gorev['guncelleme_tarihi'], true)); ?></span>
                </div>
            </div>
        </section>
    </aside>
</div>

<?php if ($proje_yonetebilir): ?>
    <dialog id="modal-gorev-duzenle" class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Görevi Düzenle</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
        </div>
        <form method="post" action="gorev_detay.php?id=<?php echo $gorev_id; ?>" data-validate-form>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="gorev_guncelle">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="duzenle-baslik">Görev Başlığı</label>
                    <input type="text" id="duzenle-baslik" name="baslik" class="form-control" value="<?php echo e($gorev['baslik']); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="duzenle-aciklama">Açıklama</label>
                    <textarea id="duzenle-aciklama" name="aciklama" class="form-control" rows="4"><?php echo e($gorev['aciklama']); ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="duzenle-atanan">Atanan Üye</label>
                        <select id="duzenle-atanan" name="atanan_id" class="form-control">
                            <option value="">Atanmadı</option>
                            <?php foreach ($proje_uyeleri as $uye): ?>
                                <option value="<?php echo (int) $uye['id']; ?>"<?php echo (int) $gorev['atanan_id'] === (int) $uye['id'] ? ' selected' : ''; ?>>
                                    <?php echo e($uye['ad_soyad']); ?> (<?php echo e($uye['proje_rolu']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="duzenle-oncelik">Öncelik</label>
                        <select id="duzenle-oncelik" name="oncelik" class="form-control">
                            <option value="dusuk"<?php echo $gorev['oncelik'] === 'dusuk' ? ' selected' : ''; ?>>Düşük</option>
                            <option value="orta"<?php echo $gorev['oncelik'] === 'orta' ? ' selected' : ''; ?>>Orta</option>
                            <option value="yuksek"<?php echo $gorev['oncelik'] === 'yuksek' ? ' selected' : ''; ?>>Yüksek</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="duzenle-birim">Alt Ekip / Birim</label>
                        <select id="duzenle-birim" name="birim" class="form-control">
                            <?php foreach ($birimler as $birim_kod => $birim_ad): ?>
                                <option value="<?php echo e($birim_kod); ?>"<?php echo $gorev_birim_kodu === $birim_kod ? ' selected' : ''; ?>>
                                    <?php echo e($birim_ad); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="duzenle-durum">Durum</label>
                        <select id="duzenle-durum" name="durum" class="form-control">
                            <option value="yapilacak"<?php echo $gorev['durum'] === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
                            <option value="devam_ediyor"<?php echo $gorev['durum'] === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
                            <option value="tamamlandi"<?php echo $gorev['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="duzenle-son-tarih">Son Tarih</label>
                        <input type="date" id="duzenle-son-tarih" name="son_tarih" class="form-control" value="<?php echo e($gorev['son_tarih']); ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
                <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
