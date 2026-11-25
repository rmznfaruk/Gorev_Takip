<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$csrf = csrf_token();

$proje_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($proje_id <= 0 && isset($_POST['proje_id'])) {
    $proje_id = (int) $_POST['proje_id'];
}

$stmt = $pdo->prepare('
    SELECT p.*, u.ad_soyad AS yonetici_adi, u.unvan AS yonetici_unvan
    FROM projeler p
    INNER JOIN kullanicilar u ON u.id = p.yonetici_id
    WHERE p.id = ?
    LIMIT 1
');
$stmt->execute(array($proje_id));
$proje = $stmt->fetch();

if (!$proje) {
    flash_ekle('error', 'Aradığınız proje bulunamadı.');
    yonlendir('projeler.php');
}

// Strict Room Isolation: Yalnizca bu odanin yoneticisi veya uyeleri erisebilir.
if (!proje_erisim_var_mi($pdo, (int) $proje['id'], $kullanici)) {
    flash_ekle('error', 'Bu gruba / odaya erişim yetkiniz bulunmuyor.');
    yonlendir('projeler.php');
}

$proje_kurucu_yetkisi = proje_yonetebilir_mi($proje, $kullanici);
$proje_lider_yetkisi = proje_lider_yetkisi_var_mi($pdo, $proje, $kullanici);
$proje_yonetebilir = $proje_kurucu_yetkisi;
$birimler = birim_secenekleri();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_dogrula($token)) {
        flash_ekle('error', 'Güvenlik doğrulaması başarısız.');
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    $islem = isset($_POST['islem']) ? $_POST['islem'] : '';

    if ($islem === 'kod_yenile') {
        if (!$proje_kurucu_yetkisi) {
            flash_ekle('error', 'Oda davet kodunu yalnızca oda kurucusu veya sistem yöneticisi yenileyebilir.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        $yeni_kod = oda_kodu_uret($pdo);
        $guncelle = $pdo->prepare('UPDATE projeler SET davet_kodu = ? WHERE id = ?');
        $guncelle->execute(array($yeni_kod, $proje_id));

        aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' oda davet kodunu yeniledi.');
        flash_ekle('success', 'Oda davet kodu yenilendi: ' . $yeni_kod);
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    if ($islem === 'duyuru_guncelle') {
        if (!$proje_lider_yetkisi) {
            flash_ekle('error', 'Oda duyurusunu güncellemek için oda lideri veya yönetici yetkisi gerekiyor.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        $duyuru = isset($_POST['duyuru']) ? trim((string) $_POST['duyuru']) : '';
        if ($duyuru === '') {
            $guncelle = $pdo->prepare('UPDATE projeler SET duyuru = NULL, duyuru_tarihi = NULL WHERE id = ?');
            $guncelle->execute(array($proje_id));
            aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' oda duyurusunu kaldırdı.');
            flash_ekle('success', 'Oda duyurusu kaldırıldı.');
        } else {
            $duyuru = metin_kisalt($duyuru, 1000);
            $guncelle = $pdo->prepare('UPDATE projeler SET duyuru = ?, duyuru_tarihi = ? WHERE id = ?');
            $guncelle->execute(array($duyuru, istanbul_simdi(), $proje_id));
            aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' oda duyurusunu güncelledi.');
            flash_ekle('success', 'Oda duyurusu güncellendi.');
        }
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    if ($islem === 'uye_yetki') {
        if (!$proje_kurucu_yetkisi) {
            flash_ekle('error', 'Üye yetkisini yalnızca oda kurucusu veya sistem yöneticisi değiştirebilir.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        $hedef_kullanici_id = isset($_POST['kullanici_id']) ? (int) $_POST['kullanici_id'] : 0;
        $yeni_yetki = isset($_POST['yetki']) ? trim((string) $_POST['yetki']) : '';
        if (!in_array($yeni_yetki, array('lider', 'uye'), true) || $hedef_kullanici_id <= 0) {
            flash_ekle('error', 'Geçersiz üye veya yetki seçimi.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        if ($hedef_kullanici_id === (int) $proje['yonetici_id']) {
            flash_ekle('error', 'Oda kurucusunun yetkisi değiştirilemez.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $hedef_stmt = $pdo->prepare('
            SELECT pu.id, u.ad_soyad
            FROM proje_uyeleri pu
            INNER JOIN kullanicilar u ON u.id = pu.kullanici_id
            WHERE pu.proje_id = ? AND pu.kullanici_id = ?
            LIMIT 1
        ');
        $hedef_stmt->execute(array($proje_id, $hedef_kullanici_id));
        $hedef_kayit = $hedef_stmt->fetch();
        if (!$hedef_kayit) {
            flash_ekle('error', 'Seçilen kullanıcı bu odanın üyesi değil.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $guncelle = $pdo->prepare('UPDATE proje_uyeleri SET yetki = ? WHERE proje_id = ? AND kullanici_id = ?');
        $guncelle->execute(array($yeni_yetki, $proje_id, $hedef_kullanici_id));

        $yetki_metni = $yeni_yetki === 'lider' ? 'Birim Lideri' : 'Üye';
        aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ', ' . $hedef_kayit['ad_soyad'] . ' yetkisini ' . $yetki_metni . ' yaptı.');
        bildirim_ekle(
            $pdo,
            $hedef_kullanici_id,
            'yetki',
            '"' . $proje['ad'] . '" odasındaki yetkiniz ' . $yetki_metni . ' olarak güncellendi.',
            'proje_detay.php?id=' . $proje_id
        );

        flash_ekle('success', $hedef_kayit['ad_soyad'] . ' için oda yetkisi "' . $yetki_metni . '" olarak güncellendi.');
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    if ($islem === 'gorev_ekle') {
        if (!$proje_lider_yetkisi) {
            flash_ekle('error', 'Görev oluşturmak için oda lideri veya yönetici yetkisi gerekiyor.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        $baslik = isset($_POST['baslik']) ? trim((string) $_POST['baslik']) : '';
        $aciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';
        $atanan_id = isset($_POST['atanan_id']) && (int) $_POST['atanan_id'] > 0 ? (int) $_POST['atanan_id'] : null;
        $oncelik = isset($_POST['oncelik']) ? (string) $_POST['oncelik'] : 'orta';
        $durum = isset($_POST['durum']) ? (string) $_POST['durum'] : 'yapilacak';
        $birim = isset($_POST['birim']) ? birim_kodu_normalize($_POST['birim']) : 'genel';
        $son_tarih = isset($_POST['son_tarih']) && $_POST['son_tarih'] !== '' ? $_POST['son_tarih'] : null;

        if (!in_array($oncelik, array('dusuk', 'orta', 'yuksek'), true)) {
            $oncelik = 'orta';
        }
        if (!in_array($durum, array('yapilacak', 'devam_ediyor', 'tamamlandi'), true)) {
            $durum = 'yapilacak';
        }

        if ($baslik === '') {
            flash_ekle('error', 'Görev başlığı boş bırakılamaz.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        // Guvenlik: Atanan kisi yalnizca bu odanin uyesi olabilir (IDOR / oda disi atama engeli).
        if ($atanan_id !== null && !proje_uyesi_mi($pdo, $proje_id, $atanan_id)) {
            flash_ekle('error', 'Görev yalnızca bu grubun üyelerine atanabilir.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $simdi = istanbul_simdi();
        $ekle = $pdo->prepare('
            INSERT INTO gorevler (proje_id, baslik, aciklama, atanan_id, olusturan_id, durum, oncelik, birim, son_tarih, olusturma_tarihi, guncelleme_tarihi)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ekle->execute(array($proje_id, $baslik, $aciklama, $atanan_id, $kullanici_id, $durum, $oncelik, $birim, $son_tarih, $simdi, $simdi));
        $yeni_gorev_id = (int) $pdo->lastInsertId();

        aktivite_kaydet(
            $pdo,
            $proje_id,
            $kullanici_id,
            $kullanici['ad_soyad'] . ' yeni görev oluşturdu: ' . $baslik
        );

        if ($atanan_id !== null) {
            bildirim_ekle(
                $pdo,
                $atanan_id,
                'gorev_atama',
                $kullanici['ad_soyad'] . ' size yeni görev atadı: ' . $baslik,
                'gorev_detay.php?id=' . $yeni_gorev_id
            );
        }

        flash_ekle('success', 'Yeni görev gruba eklendi.');
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    if ($islem === 'uye_ekle') {
        if (!$proje_kurucu_yetkisi || !yonetici_mi($kullanici)) {
            flash_ekle('error', 'Yeni üyeleri oda davet kodu ile gruba davet edebilirsiniz.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $eklenecek_id = isset($_POST['kullanici_id']) ? (int) $_POST['kullanici_id'] : 0;
        $proje_rolu = isset($_POST['proje_rolu']) ? trim((string) $_POST['proje_rolu']) : 'Ekip Üyesi';
        if ($proje_rolu === '') {
            $proje_rolu = 'Ekip Üyesi';
        }

        if ($eklenecek_id <= 0) {
            flash_ekle('error', 'Lütfen eklenecek kullanıcıyı seçin.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $mevcut = $pdo->prepare('SELECT id FROM proje_uyeleri WHERE proje_id = ? AND kullanici_id = ? LIMIT 1');
        $mevcut->execute(array($proje_id, $eklenecek_id));
        if ($mevcut->fetch()) {
            flash_ekle('error', 'Seçilen kullanıcı zaten bu projede yer alıyor.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $ekle = $pdo->prepare('INSERT INTO proje_uyeleri (proje_id, kullanici_id, proje_rolu, yetki, katilim_tarihi) VALUES (?, ?, ?, ?, ?)');
        $ekle->execute(array($proje_id, $eklenecek_id, $proje_rolu, 'uye', istanbul_simdi()));

        aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' odaya yeni bir üye ekledi.');
        bildirim_ekle(
            $pdo,
            $eklenecek_id,
            'oda_davet',
            '"' . $proje['ad'] . '" çalışma odasına eklendiniz.',
            'proje_detay.php?id=' . $proje_id
        );

        flash_ekle('success', 'Ekip üyesi projeye eklendi.');
        yonlendir('proje_detay.php?id=' . $proje_id);
    }

    if ($islem === 'uye_cikar') {
        if (!$proje_kurucu_yetkisi) {
            flash_ekle('error', 'Üye çıkarma yetkisi yalnızca oda kurucusuna veya sistem yöneticisine aittir.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }
        $cikarilacak_id = isset($_POST['kullanici_id']) ? (int) $_POST['kullanici_id'] : 0;
        if ($cikarilacak_id === (int) $proje['yonetici_id']) {
            flash_ekle('error', 'Proje yöneticisi projeden çıkarılamaz.');
            yonlendir('proje_detay.php?id=' . $proje_id);
        }

        $sil = $pdo->prepare('DELETE FROM proje_uyeleri WHERE proje_id = ? AND kullanici_id = ?');
        $sil->execute(array($proje_id, $cikarilacak_id));

        aktivite_kaydet($pdo, $proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' bir üyeyi odadan çıkardı.');
        flash_ekle('success', 'Üye projeden çıkarıldı.');
        yonlendir('proje_detay.php?id=' . $proje_id);
    }
}

// Proje uyeleri
$stmt = $pdo->prepare('
    SELECT pu.id, pu.proje_rolu, pu.yetki, pu.katilim_tarihi, u.id AS kullanici_id, u.ad_soyad, u.unvan, u.rol
    FROM proje_uyeleri pu
    INNER JOIN kullanicilar u ON u.id = pu.kullanici_id
    WHERE pu.proje_id = ?
    ORDER BY
        CASE WHEN u.id = ? THEN 0 WHEN pu.yetki = \'lider\' THEN 1 ELSE 2 END,
        u.ad_soyad ASC
');
$stmt->execute(array($proje_id, (int) $proje['yonetici_id']));
$uyeler = $stmt->fetchAll();

// Projede henuz olmayan kullanicilar (yalnizca sistem yoneticisi icin)
$aday_kullanicilar = array();
if ($proje_kurucu_yetkisi && yonetici_mi($kullanici)) {
    $stmt = $pdo->prepare('
        SELECT id, ad_soyad, unvan
        FROM kullanicilar
        WHERE id NOT IN (SELECT kullanici_id FROM proje_uyeleri WHERE proje_id = ?)
        ORDER BY ad_soyad ASC
    ');
    $stmt->execute(array($proje_id));
    $aday_kullanicilar = $stmt->fetchAll();
}

// Birim filtresi (GET ?birim=...)
$filtre_birim = isset($_GET['birim']) ? birim_kodu_normalize($_GET['birim'], '') : '';

// Projenin gorevleri (alt gorev sayilari dahil)
$gorev_sql = '
    SELECT g.*, u.ad_soyad AS atanan_adi,
           (SELECT COUNT(*) FROM yorumlar y WHERE y.gorev_id = g.id) AS yorum_sayisi,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id) AS alt_toplam,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id AND ag.tamamlandi = 1) AS alt_tamamlanan
    FROM gorevler g
    LEFT JOIN kullanicilar u ON u.id = g.atanan_id
    WHERE g.proje_id = ?
';
$gorev_params = array($proje_id);
if ($filtre_birim !== '') {
    $birim_degerleri = birim_filtre_degerleri($filtre_birim);
    $birim_ph = implode(',', array_fill(0, count($birim_degerleri), '?'));
    $gorev_sql .= ' AND g.birim IN (' . $birim_ph . ')';
    $gorev_params = array_merge($gorev_params, $birim_degerleri);
}
$gorev_sql .= '
    ORDER BY
        CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END,
        g.olusturma_tarihi DESC
';
$stmt = $pdo->prepare($gorev_sql);
$stmt->execute($gorev_params);
$tum_gorevler = $stmt->fetchAll();

$sutunlar = array(
    'yapilacak' => array('baslik' => 'Yapılacak', 'nokta' => 'col-dot-todo', 'gorevler' => array()),
    'devam_ediyor' => array('baslik' => 'Devam Ediyor', 'nokta' => 'col-dot-progress', 'gorevler' => array()),
    'tamamlandi' => array('baslik' => 'Tamamlandı', 'nokta' => 'col-dot-done', 'gorevler' => array()),
);
$proje_toplam_gorev = count($tum_gorevler);
$proje_biten_gorev = 0;
foreach ($tum_gorevler as $idx => $g) {
    $g['ilerleme'] = gorev_ilerleme_hesapla($g, $g['alt_toplam'], $g['alt_tamamlanan']);
    $g['teslim_durumu'] = teslim_durumu_hesapla($g['son_tarih'], $g['durum']);
    $tum_gorevler[$idx] = $g;
    if ($g['durum'] === 'tamamlandi') {
        $proje_biten_gorev++;
    }
    if (isset($sutunlar[$g['durum']])) {
        $sutunlar[$g['durum']]['gorevler'][] = $g;
    }
}
$proje_ilerleme_yuzde = gorev_listesi_ortalama_ilerleme($tum_gorevler);

// Oda aktivite akisi (son 20 kayit)
$akt_stmt = $pdo->prepare('
    SELECT a.id, a.proje_id, a.kullanici_id, a.mesaj, a.olusturma_tarihi, u.ad_soyad
    FROM aktiviteler a
    LEFT JOIN kullanicilar u ON u.id = a.kullanici_id
    WHERE a.proje_id = ?
    ORDER BY a.olusturma_tarihi DESC, a.id DESC
    LIMIT 20
');
$akt_stmt->execute(array($proje_id));
$aktiviteler = $akt_stmt->fetchAll();

$pdurum = proje_durum_etiketi($proje['durum']);
$sayfa_basligi = $proje['ad'];
$aktif_sayfa = 'projeler';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header room-header-top">
    <div>
        <div class="stack-sm" style="margin-bottom:0.35rem;">
            <a href="projeler.php" class="btn btn-ghost btn-sm">&larr; Çalışma Odaları</a>
        </div>
        <h1 class="page-title" style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
            <span class="code-chip"><?php echo e($proje['kod']); ?></span>
            <span><?php echo e($proje['ad']); ?></span>
            <span class="badge badge-yonetici mono">%<?php echo (int) $proje_ilerleme_yuzde; ?></span>
        </h1>
        <p class="page-subtitle">
            <span class="badge <?php echo e($pdurum['sinif']); ?>"><?php echo e($pdurum['metin']); ?></span>
            &nbsp;Ekip Lideri: <?php echo e($proje['yonetici_adi']); ?>
            &middot; <?php echo (int) $proje_biten_gorev; ?>/<?php echo (int) $proje_toplam_gorev; ?> görev tamamlandı
            <?php if (!empty($proje['bitis_tarihi'])): ?>
                &middot; Hedef: <?php echo e(tarih_format($proje['bitis_tarihi'])); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <div class="avatar-stack no-print" title="<?php echo count($uyeler); ?> oda üyesi">
            <?php foreach (array_slice($uyeler, 0, 5) as $av_uye): ?>
                <span class="avatar avatar-sm" title="<?php echo e($av_uye['ad_soyad']); ?>"><?php echo e(bas_harfler($av_uye['ad_soyad'])); ?></span>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-print-report>PDF Rapor Al</button>
        <?php if ($proje_lider_yetkisi): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-modal-open="modal-duyuru">Duyuru</button>
            <button type="button" class="btn btn-primary btn-sm" data-modal-open="modal-yeni-gorev">+ Yıllık / Ana Görev Ata</button>
        <?php endif; ?>
    </div>
</div>

<div class="progress-bar" style="margin-bottom:1.15rem;" aria-label="Oda tamamlanma oranı %<?php echo (int) $proje_ilerleme_yuzde; ?>">
    <div class="progress-fill" style="width: <?php echo (int) $proje_ilerleme_yuzde; ?>%;"></div>
</div>

<?php if (!empty($proje['duyuru'])): ?>
    <div class="announcement-bar" role="region" aria-label="Oda duyurusu">
        <div class="announcement-main">
            <span class="announcement-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
            </span>
            <div>
                <div class="announcement-title">Sabit Oda Duyurusu</div>
                <div class="announcement-text"><?php echo nl2br(e($proje['duyuru'])); ?></div>
                <?php if (!empty($proje['duyuru_tarihi'])): ?>
                    <div class="announcement-date">Güncelleme: <?php echo e(tarih_format($proje['duyuru_tarihi'], true)); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($proje_lider_yetkisi): ?>
            <button type="button" class="btn btn-secondary btn-sm no-print" data-modal-open="modal-duyuru">Düzenle</button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="room-code-box">
    <div class="room-code-meta">
        <span class="room-code-label">Oda Davet Kodu:</span>
        <span class="room-code-value"><?php echo e($proje['davet_kodu']); ?></span>
        <span class="room-code-hint no-print">Ekip arkadaşlarınız bu kod ile odaya doğrudan katılabilir.</span>
    </div>
    <div class="room-code-actions no-print">
        <button type="button" class="btn btn-secondary btn-sm" data-copy-text="<?php echo e($proje['davet_kodu']); ?>">Kopyala</button>
        <?php if ($proje_kurucu_yetkisi): ?>
            <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>" style="display:inline;" data-confirm="Yeni bir oda kodu üretilecek ve eski kod geçersiz olacaktır. Onaylıyor musunuz?">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="islem" value="kod_yenile">
                <button type="submit" class="btn btn-ghost btn-sm">Yenile</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="tabs room-tabs no-print" role="tablist" aria-label="Oda görünümleri">
    <button type="button" class="tab-link active" role="tab" aria-selected="true" data-tab-target="kanban">
        <span>Kanban Panosu</span>
        <span class="tab-count"><?php echo (int) $proje_toplam_gorev; ?></span>
    </button>
    <button type="button" class="tab-link" role="tab" aria-selected="false" data-tab-target="liste">
        <span>Liste Görünümü</span>
    </button>
    <button type="button" class="tab-link" role="tab" aria-selected="false" data-tab-target="uyeler">
        <span>Üyeler &amp; Birim Liderleri</span>
        <span class="tab-count"><?php echo count($uyeler); ?></span>
    </button>
    <button type="button" class="tab-link" role="tab" aria-selected="false" data-tab-target="aktivite">
        <span>Aktivite Akışı</span>
        <span class="tab-count"><?php echo count($aktiviteler); ?></span>
    </button>
</div>

<div class="unit-filter-bar no-print"<?php echo $filtre_birim !== '' ? ' data-server-filtered="1"' : ''; ?> role="group" aria-label="Birim filtresi">
    <div class="unit-filter-list">
        <a href="proje_detay.php?id=<?php echo $proje_id; ?>"
           class="unit-filter-btn<?php echo $filtre_birim === '' ? ' is-active' : ''; ?>"
           data-unit-filter=""
           aria-pressed="<?php echo $filtre_birim === '' ? 'true' : 'false'; ?>">Tümü</a>
        <?php foreach ($birimler as $birim_kod => $birim_ad): ?>
            <a href="proje_detay.php?id=<?php echo $proje_id; ?>&amp;birim=<?php echo e($birim_kod); ?>"
               class="unit-filter-btn<?php echo $filtre_birim === $birim_kod ? ' is-active' : ''; ?>"
               data-unit-filter="<?php echo e($birim_kod); ?>"
               aria-pressed="<?php echo $filtre_birim === $birim_kod ? 'true' : 'false'; ?>"><?php echo e($birim_ad); ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- SEKME 1: KANBAN -->
<section class="tab-panel" data-tab-panel="kanban" aria-label="Kanban Panosu">
    <div class="kanban-grid">
        <?php foreach ($sutunlar as $durum_kodu => $sutun): ?>
            <div class="kanban-col" data-status="<?php echo e($durum_kodu); ?>">
                <div class="kanban-col-header">
                    <span class="kanban-col-title">
                        <span class="col-dot <?php echo e($sutun['nokta']); ?>" aria-hidden="true"></span>
                        <span><?php echo e($sutun['baslik']); ?></span>
                        <span class="kanban-count"><?php echo count($sutun['gorevler']); ?></span>
                    </span>
                </div>
                <div class="kanban-list">
                    <?php foreach ($sutun['gorevler'] as $gorev): ?>
                        <?php
                        $oncelik = oncelik_etiketi($gorev['oncelik']);
                        $birim_bilgi = birim_etiketi(isset($gorev['birim']) ? $gorev['birim'] : 'genel');
                        $gorev_birim_kodu = $birim_bilgi['kod'];
                        $durum_sinifi = 'status-' . $gorev['durum'];
                        $durum_degistirebilir = $proje_lider_yetkisi || ($gorev['atanan_id'] !== null && (int) $gorev['atanan_id'] === $kullanici_id);
                        $g_alt_toplam = (int) $gorev['alt_toplam'];
                        $g_alt_biten = (int) $gorev['alt_tamamlanan'];
                        $g_ilerleme = (int) $gorev['ilerleme'];
                        ?>
                        <article class="task-card" data-filter-item data-unit="<?php echo e($gorev_birim_kodu); ?>">
                            <div class="task-card-top">
                                <span class="code-chip"><?php echo e($proje['kod']); ?> #<?php echo (int) $gorev['id']; ?></span>
                                <span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo e($birim_bilgi['metin']); ?></span>
                                <span class="priority-indicator priority-<?php echo e($gorev['oncelik']); ?>">
                                    <span class="priority-dot" aria-hidden="true"></span>
                                    <span><?php echo e($oncelik['metin']); ?></span>
                                </span>
                            </div>
                            <h3 class="task-card-title">
                                <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>">
                                    <?php echo e($gorev['baslik']); ?>
                                </a>
                            </h3>
                            <div class="task-card-progress">
                                <div class="task-card-progress-meta">
                                    <span>%<?php echo $g_ilerleme; ?></span>
                                    <span><?php echo $g_alt_toplam > 0 ? ($g_alt_biten . '/' . $g_alt_toplam . ' alt görev') : 'Ana görev'; ?></span>
                                </div>
                                <div class="task-card-progress-bar">
                                    <div class="task-card-progress-fill<?php echo $g_ilerleme >= 100 ? ' is-complete' : ''; ?>" style="width: <?php echo $g_ilerleme; ?>%;"></div>
                                </div>
                            </div>
                            <div class="task-card-meta">
                                <?php if ($gorev['atanan_adi']): ?>
                                    <span class="assignee-cell">
                                        <span class="avatar-xs" aria-hidden="true"><?php echo e(bas_harfler($gorev['atanan_adi'])); ?></span>
                                        <span><?php echo e($gorev['atanan_adi']); ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="assignee-unassigned">Atanmadı</span>
                                <?php endif; ?>
                                <span class="date-muted"><?php echo e(tarih_format($gorev['son_tarih'])); ?></span>
                            </div>
                            <div class="task-card-footer">
                                <?php if ($durum_degistirebilir): ?>
                                    <select class="status-select <?php echo e($durum_sinifi); ?>"
                                            aria-label="Görev durumunu değiştir"
                                            data-task-status
                                            data-task-id="<?php echo (int) $gorev['id']; ?>"
                                            data-csrf="<?php echo e($csrf); ?>">
                                        <option value="yapilacak"<?php echo $gorev['durum'] === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
                                        <option value="devam_ediyor"<?php echo $gorev['durum'] === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
                                        <option value="tamamlandi"<?php echo $gorev['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                                    </select>
                                <?php else: ?>
                                    <?php $gd_kart = durum_etiketi($gorev['durum']); ?>
                                    <span class="status-pill <?php echo e($durum_sinifi); ?>">
                                        <span class="status-dot" aria-hidden="true"></span>
                                        <span class="status-pill-text"><?php echo e($gd_kart['metin']); ?></span>
                                    </span>
                                <?php endif; ?>
                                <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>#haftalik-plan" class="btn btn-ghost btn-sm">Haftalık Plan (<?php echo $g_alt_biten; ?>/<?php echo $g_alt_toplam; ?>) &rarr;</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- SEKME 2: LİSTE GÖRÜNÜMÜ -->
<section class="tab-panel" data-tab-panel="liste" hidden aria-label="Liste Görünümü">
    <div class="card card-flush">
        <?php if (empty($tum_gorevler)): ?>
            <div class="empty-state">
                <div class="empty-state-title">Bu odada henüz görev yok</div>
                <p>İlk ana görevi ekleyip içine Hafta 1, Hafta 2 alt görevleri planlayabilirsiniz.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Görev</th>
                            <th>Birim</th>
                            <th>Öncelik</th>
                            <th>İlerleme</th>
                            <th>Atanan</th>
                            <th>Son Tarih</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tum_gorevler as $gorev): ?>
                            <?php
                            $oncelik = oncelik_etiketi($gorev['oncelik']);
                            $birim_bilgi = birim_etiketi(isset($gorev['birim']) ? $gorev['birim'] : 'genel');
                            $gorev_birim_kodu = $birim_bilgi['kod'];
                            $durum_sinifi = 'status-' . $gorev['durum'];
                            $durum_degistirebilir = $proje_lider_yetkisi || ($gorev['atanan_id'] !== null && (int) $gorev['atanan_id'] === $kullanici_id);
                            $g_ilerleme = (int) $gorev['ilerleme'];
                            ?>
                            <tr data-filter-item data-unit="<?php echo e($gorev_birim_kodu); ?>">
                                <td data-label="Görev">
                                    <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>" class="task-title-link">
                                        <span class="task-id-muted">#<?php echo (int) $gorev['id']; ?></span>
                                        <span><?php echo e($gorev['baslik']); ?></span>
                                    </a>
                                    <div style="margin-top:0.2rem;">
                                        <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>#haftalik-plan" class="text-muted" style="font-size:0.76rem;">
                                            Haftalık Plan (<?php echo (int) $gorev['alt_tamamlanan']; ?>/<?php echo (int) $gorev['alt_toplam']; ?>) &rarr;
                                        </a>
                                    </div>
                                </td>
                                <td data-label="Birim"><span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo e($birim_bilgi['metin']); ?></span></td>
                                <td data-label="Öncelik">
                                    <span class="priority-indicator priority-<?php echo e($gorev['oncelik']); ?>">
                                        <span class="priority-dot" aria-hidden="true"></span>
                                        <span><?php echo e($oncelik['metin']); ?></span>
                                    </span>
                                </td>
                                <td data-label="İlerleme">
                                    <div class="inline-progress">
                                        <div class="inline-progress-bar">
                                            <div class="inline-progress-fill<?php echo $g_ilerleme >= 100 ? ' is-complete' : ''; ?>" style="width: <?php echo $g_ilerleme; ?>%;"></div>
                                        </div>
                                        <span class="inline-progress-val">%<?php echo $g_ilerleme; ?></span>
                                    </div>
                                </td>
                                <td data-label="Atanan">
                                    <?php if ($gorev['atanan_adi']): ?>
                                        <span class="assignee-cell">
                                            <span class="avatar-xs" aria-hidden="true"><?php echo e(bas_harfler($gorev['atanan_adi'])); ?></span>
                                            <span><?php echo e($gorev['atanan_adi']); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="assignee-unassigned">Atanmadı</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Son Tarih" class="date-muted"><?php echo e(tarih_format($gorev['son_tarih'])); ?></td>
                                <td data-label="Durum">
                                    <?php if ($durum_degistirebilir): ?>
                                        <select class="status-select <?php echo e($durum_sinifi); ?>"
                                                aria-label="Görev durumunu değiştir"
                                                data-task-status
                                                data-task-id="<?php echo (int) $gorev['id']; ?>"
                                                data-csrf="<?php echo e($csrf); ?>">
                                            <option value="yapilacak"<?php echo $gorev['durum'] === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
                                            <option value="devam_ediyor"<?php echo $gorev['durum'] === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
                                            <option value="tamamlandi"<?php echo $gorev['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                                        </select>
                                    <?php else: ?>
                                        <?php $gd = durum_etiketi($gorev['durum']); ?>
                                        <span class="status-pill <?php echo e($durum_sinifi); ?>">
                                            <span class="status-dot" aria-hidden="true"></span>
                                            <span class="status-pill-text"><?php echo e($gd['metin']); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- SEKME 3: ÜYELER & BİRİM LİDERLERİ -->
<section class="tab-panel" data-tab-panel="uyeler" hidden aria-label="Üyeler ve Birim Liderleri">
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="section-title">Oda Üyeleri ve Yetkiler (<?php echo count($uyeler); ?>)</h2>
            <?php if ($proje_kurucu_yetkisi && yonetici_mi($kullanici)): ?>
                <button type="button" class="btn btn-secondary btn-sm" data-modal-open="modal-uye-ekle">+ Üye Ekle</button>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Üye</th>
                        <th>Unvan / Rol</th>
                        <th>Oda Yetkisi</th>
                        <?php if ($proje_kurucu_yetkisi): ?>
                            <th style="text-align:right;">İşlem</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($uyeler as $uye): ?>
                        <?php
                        $kurucu_mu = ((int) $uye['kullanici_id'] === (int) $proje['yonetici_id']);
                        $birim_lideri_mi = ($uye['yetki'] === 'lider');
                        ?>
                        <tr>
                            <td data-label="Üye">
                                <span class="assignee-cell">
                                    <span class="avatar avatar-sm"><?php echo e(bas_harfler($uye['ad_soyad'])); ?></span>
                                    <strong><?php echo e($uye['ad_soyad']); ?></strong>
                                </span>
                            </td>
                            <td data-label="Unvan / Rol" class="text-muted"><?php echo e($uye['unvan'] !== '' ? $uye['unvan'] : $uye['proje_rolu']); ?></td>
                            <td data-label="Oda Yetkisi">
                                <?php if ($kurucu_mu): ?>
                                    <span class="badge badge-yonetici">Ekip Lideri</span>
                                <?php elseif ($birim_lideri_mi): ?>
                                    <span class="badge badge-lider">Birim Lideri</span>
                                <?php else: ?>
                                    <span class="badge badge-uye">Üye</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($proje_kurucu_yetkisi): ?>
                                <td data-label="İşlem" class="cell-actions-right">
                                    <?php if (!$kurucu_mu): ?>
                                        <div class="table-action-group">
                                            <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>" class="inline-action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                                <input type="hidden" name="islem" value="uye_yetki">
                                                <input type="hidden" name="kullanici_id" value="<?php echo (int) $uye['kullanici_id']; ?>">
                                                <input type="hidden" name="yetki" value="<?php echo $birim_lideri_mi ? 'uye' : 'lider'; ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">
                                                    <?php echo $birim_lideri_mi ? 'Üye Yap' : 'Birim Lideri Yap'; ?>
                                                </button>
                                            </form>
                                            <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>" class="inline-action-form" data-confirm="Bu üyeyi odadan çıkarmak istediğinize emin misiniz?">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                                <input type="hidden" name="islem" value="uye_cikar">
                                                <input type="hidden" name="kullanici_id" value="<?php echo (int) $uye['kullanici_id']; ?>">
                                                <button type="submit" class="btn btn-ghost btn-sm text-danger">Çıkar</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.78rem;">Ekip Lideri</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- SEKME 4: AKTİVİTE AKIŞI -->
<section class="tab-panel" data-tab-panel="aktivite" hidden aria-label="Aktivite Akışı">
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="section-title">Oda Aktivite Geçmişi</h2>
        </div>
        <?php if (empty($aktiviteler)): ?>
            <div class="empty-state">
                <p>Bu odada henüz kaydedilmiş bir aktivite yok.</p>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($aktiviteler as $akt): ?>
                    <div class="activity-item">
                        <span class="activity-dot" aria-hidden="true"></span>
                        <div class="activity-body">
                            <div class="activity-text"><?php echo e($akt['mesaj']); ?></div>
                            <div class="activity-time"><?php echo e(tarih_format($akt['olusturma_tarihi'], true)); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($proje_lider_yetkisi): ?>
    <dialog id="modal-duyuru" class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Oda Duyurusu</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
        </div>
        <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="duyuru_guncelle">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="oda-duyuru-metin">Sabit Duyuru Metni</label>
                    <textarea id="oda-duyuru-metin" name="duyuru" class="form-control" rows="4" maxlength="1000" placeholder="Örn: Cuma 18:00'e kadar Hafta 1 alt görevleri tamamlanacak."><?php echo e(isset($proje['duyuru']) ? $proje['duyuru'] : ''); ?></textarea>
                    <span class="form-hint">Boş bırakıp kaydederseniz duyuru şeridi kaldırılır.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
                <button type="submit" class="btn btn-primary">Duyuruyu Kaydet</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($proje_lider_yetkisi): ?>
    <dialog id="modal-yeni-gorev" class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Yıllık / Ana Görev Ata</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
        </div>
        <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>" data-validate-form>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="gorev_ekle">
            <div class="modal-body">
                <p class="form-hint" style="margin-top:0;margin-bottom:0.85rem;">Ekip üyelerine yıllık genel görevlerini atayın, ardından görevin içine girip Hafta 1, Hafta 2 şeklinde haftalık alt görevler ekleyin.</p>
                <div class="form-group">
                    <label class="form-label" for="gorev-baslik">Yıllık / Ana Görev Başlığı</label>
                    <input type="text" id="gorev-baslik" name="baslik" class="form-control" placeholder="Örn: Yıllık Backend API Geliştirme ve Bakım" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="gorev-aciklama">Açıklama</label>
                    <textarea id="gorev-aciklama" name="aciklama" class="form-control" rows="3" placeholder="Görevden beklenen çıktıyı belirtin."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="gorev-atanan">Atanan Üye</label>
                        <select id="gorev-atanan" name="atanan_id" class="form-control">
                            <option value="">Atanmadı</option>
                            <?php foreach ($uyeler as $uye): ?>
                                <option value="<?php echo (int) $uye['kullanici_id']; ?>"><?php echo e($uye['ad_soyad']); ?> (<?php echo e($uye['proje_rolu']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="gorev-oncelik">Öncelik</label>
                        <select id="gorev-oncelik" name="oncelik" class="form-control">
                            <option value="dusuk">Düşük</option>
                            <option value="orta" selected>Orta</option>
                            <option value="yuksek">Yüksek</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="gorev-birim">Alt Ekip / Birim</label>
                        <select id="gorev-birim" name="birim" class="form-control">
                            <?php foreach ($birimler as $birim_kod => $birim_ad): ?>
                                <option value="<?php echo e($birim_kod); ?>"><?php echo e($birim_ad); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="gorev-durum">Başlangıç Durumu</label>
                        <select id="gorev-durum" name="durum" class="form-control">
                            <option value="yapilacak" selected>Yapılacak</option>
                            <option value="devam_ediyor">Devam Ediyor</option>
                            <option value="tamamlandi">Tamamlandı</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="gorev-son-tarih">Son Tarih</label>
                        <input type="date" id="gorev-son-tarih" name="son_tarih" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
                <button type="submit" class="btn btn-primary">Yıllık Görevi Ata</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($proje_kurucu_yetkisi && yonetici_mi($kullanici)): ?>
    <dialog id="modal-uye-ekle" class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Projeye Üye Ekle</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
        </div>
        <form method="post" action="proje_detay.php?id=<?php echo $proje_id; ?>" data-validate-form>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="uye_ekle">
            <div class="modal-body">
                <?php if (empty($aday_kullanicilar)): ?>
                    <p class="page-subtitle">Sistemdeki tüm kayıtlı kullanıcılar bu projede yer alıyor.</p>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label" for="uye-kullanici">Kullanıcı</label>
                        <select id="uye-kullanici" name="kullanici_id" class="form-control" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($aday_kullanicilar as $aday): ?>
                                <option value="<?php echo (int) $aday['id']; ?>"><?php echo e($aday['ad_soyad']); ?> — <?php echo e($aday['unvan']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="uye-rol">Proje Rolü</label>
                        <input type="text" id="uye-rol" name="proje_rolu" class="form-control" value="Geliştirici" required>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Kapat</button>
                <?php if (!empty($aday_kullanicilar)): ?>
                    <button type="submit" class="btn btn-primary">Üyeyi Ekle</button>
                <?php endif; ?>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
