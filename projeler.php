<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$yonetici = yonetici_mi($kullanici);
$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_dogrula($token)) {
        flash_ekle('error', 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.');
        yonlendir('projeler.php');
    }

    $islem = isset($_POST['islem']) ? $_POST['islem'] : '';

    if ($islem === 'koda_katil') {
        $oda_kodu = isset($_POST['oda_kodu']) ? strtoupper(trim((string) $_POST['oda_kodu'])) : '';
        $donus_url = (isset($_POST['kaynak']) && $_POST['kaynak'] === 'panel') ? 'panel.php' : 'projeler.php';

        if ($oda_kodu === '') {
            flash_ekle('error', 'Lütfen katılmak istediğiniz grubun davet kodunu girin.');
            yonlendir($donus_url);
        }

        $oda_stmt = $pdo->prepare('SELECT id, ad, yonetici_id FROM projeler WHERE UPPER(davet_kodu) = ? LIMIT 1');
        $oda_stmt->execute(array($oda_kodu));
        $oda = $oda_stmt->fetch();

        if (!$oda) {
            flash_ekle('error', 'Girilen oda kodu ile eşleşen bir grup bulunamadı.');
            yonlendir($donus_url);
        }

        $oda_id = (int) $oda['id'];
        $mevcut_uye = $pdo->prepare('SELECT id FROM proje_uyeleri WHERE proje_id = ? AND kullanici_id = ? LIMIT 1');
        $mevcut_uye->execute(array($oda_id, $kullanici_id));
        if (!$mevcut_uye->fetch()) {
            $ekle_uye = $pdo->prepare('INSERT INTO proje_uyeleri (proje_id, kullanici_id, proje_rolu, yetki, katilim_tarihi) VALUES (?, ?, ?, ?, ?)');
            $ekle_uye->execute(array($oda_id, $kullanici_id, 'Ekip Üyesi', 'uye', istanbul_simdi()));
            aktivite_kaydet($pdo, $oda_id, $kullanici_id, $kullanici['ad_soyad'] . ' davet koduyla odaya katıldı.');
            bildirim_ekle($pdo, (int) $oda['yonetici_id'], 'oda_katilim', $kullanici['ad_soyad'] . ', "' . $oda['ad'] . '" odasına katıldı.', 'proje_detay.php?id=' . $oda_id);
            flash_ekle('success', '"' . $oda['ad'] . '" grubuna katıldınız.');
        } else {
            flash_ekle('info', 'Bu grupta zaten üyesiniz.');
        }

        yonlendir('proje_detay.php?id=' . $oda_id);
    }

    if ($islem === 'olustur' || $islem === 'proje_ekle') {
        $ad = isset($_POST['ad']) ? trim((string) $_POST['ad']) : '';
        $kod = isset($_POST['kod']) ? strtoupper(trim((string) $_POST['kod'])) : '';
        $aciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';
        $durum = isset($_POST['durum']) ? (string) $_POST['durum'] : 'aktif';
        $baslangic = isset($_POST['baslangic_tarihi']) && $_POST['baslangic_tarihi'] !== '' ? $_POST['baslangic_tarihi'] : null;
        $bitis = isset($_POST['bitis_tarihi']) && $_POST['bitis_tarihi'] !== '' ? $_POST['bitis_tarihi'] : null;

        if (!in_array($durum, array('aktif', 'tamamlandi', 'arsiv'), true)) {
            $durum = 'aktif';
        }

        if ($ad === '') {
            flash_ekle('error', 'Grup / proje adı zorunludur.');
            yonlendir('projeler.php');
        }

        if ($kod === '') {
            $kod = 'GRP-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        }

        $kod_kontrol = $pdo->prepare('SELECT id FROM projeler WHERE kod = ? LIMIT 1');
        $kod_kontrol->execute(array($kod));
        if ($kod_kontrol->fetch()) {
            flash_ekle('error', 'Bu proje kodu zaten kullanılıyor. Farklı bir kod seçin.');
            yonlendir('projeler.php');
        }

        $davet_kodu = oda_kodu_uret($pdo);

        $simdi = istanbul_simdi();
        $ekle = $pdo->prepare('
            INSERT INTO projeler (ad, kod, davet_kodu, aciklama, yonetici_id, durum, baslangic_tarihi, bitis_tarihi, olusturma_tarihi)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ekle->execute(array($ad, $kod, $davet_kodu, $aciklama, $kullanici_id, $durum, $baslangic, $bitis, $simdi));
        $yeni_proje_id = (int) $pdo->lastInsertId();

        $uye_ekle = $pdo->prepare('INSERT INTO proje_uyeleri (proje_id, kullanici_id, proje_rolu, yetki, katilim_tarihi) VALUES (?, ?, ?, ?, ?)');
        $uye_ekle->execute(array($yeni_proje_id, $kullanici_id, 'Ekip Lideri', 'lider', $simdi));

        aktivite_kaydet($pdo, $yeni_proje_id, $kullanici_id, $kullanici['ad_soyad'] . ' çalışma odasını oluşturdu.');

        flash_ekle('success', 'Çalışma odası oluşturuldu ve Ekip Lideri olarak atandınız. Oda davet kodu: ' . $davet_kodu);
        yonlendir('proje_detay.php?id=' . $yeni_proje_id);
    }

    if ($islem === 'proje_guncelle') {
        $proje_id = isset($_POST['proje_id']) ? (int) $_POST['proje_id'] : 0;
        $mevcut_stmt = $pdo->prepare('SELECT id, yonetici_id FROM projeler WHERE id = ? LIMIT 1');
        $mevcut_stmt->execute(array($proje_id));
        $mevcut_proje = $mevcut_stmt->fetch();

        if (!$mevcut_proje || !proje_erisim_var_mi($pdo, $proje_id, $kullanici) || !proje_yonetebilir_mi($mevcut_proje, $kullanici)) {
            flash_ekle('error', 'Bu grubu düzenleme yetkiniz yok.');
            yonlendir('projeler.php');
        }

        $ad = isset($_POST['ad']) ? trim((string) $_POST['ad']) : '';
        $kod = isset($_POST['kod']) ? strtoupper(trim((string) $_POST['kod'])) : '';
        $aciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';
        $durum = isset($_POST['durum']) ? (string) $_POST['durum'] : 'aktif';
        $baslangic = isset($_POST['baslangic_tarihi']) && $_POST['baslangic_tarihi'] !== '' ? $_POST['baslangic_tarihi'] : null;
        $bitis = isset($_POST['bitis_tarihi']) && $_POST['bitis_tarihi'] !== '' ? $_POST['bitis_tarihi'] : null;

        if (!in_array($durum, array('aktif', 'tamamlandi', 'arsiv'), true)) {
            $durum = 'aktif';
        }

        if ($ad === '' || $kod === '') {
            flash_ekle('error', 'Grup adı ve kodu boş bırakılamaz.');
            yonlendir('projeler.php');
        }

        $kod_kontrol = $pdo->prepare('SELECT id FROM projeler WHERE kod = ? AND id <> ? LIMIT 1');
        $kod_kontrol->execute(array($kod, $proje_id));
        if ($kod_kontrol->fetch()) {
            flash_ekle('error', 'Bu proje kodu başka bir grupta kullanılıyor.');
            yonlendir('projeler.php');
        }

        $guncelle = $pdo->prepare('
            UPDATE projeler
            SET ad = ?, kod = ?, aciklama = ?, durum = ?, baslangic_tarihi = ?, bitis_tarihi = ?
            WHERE id = ?
        ');
        $guncelle->execute(array($ad, $kod, $aciklama, $durum, $baslangic, $bitis, $proje_id));

        flash_ekle('success', 'Grup bilgileri güncellendi.');
        yonlendir('projeler.php');
    }

    if ($islem === 'proje_sil') {
        $proje_id = isset($_POST['proje_id']) ? (int) $_POST['proje_id'] : 0;
        $mevcut_stmt = $pdo->prepare('SELECT id, yonetici_id FROM projeler WHERE id = ? LIMIT 1');
        $mevcut_stmt->execute(array($proje_id));
        $mevcut_proje = $mevcut_stmt->fetch();

        if (!$mevcut_proje || !proje_erisim_var_mi($pdo, $proje_id, $kullanici) || !proje_yonetebilir_mi($mevcut_proje, $kullanici)) {
            flash_ekle('error', 'Bu grubu silme yetkiniz yok.');
            yonlendir('projeler.php');
        }

        $sil = $pdo->prepare('DELETE FROM projeler WHERE id = ?');
        $sil->execute(array($proje_id));

        flash_ekle('success', 'Grup ve bağlı görevleri silindi.');
        yonlendir('projeler.php');
    }
}

$filtre_q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filtre_durum = isset($_GET['durum']) ? trim((string) $_GET['durum']) : '';

// Oda izolasyonu: Kullanici yalnizca yoneticisi veya uyesi oldugu gruplari gorur.
$kosullar = array(
    '(p.yonetici_id = ? OR EXISTS (SELECT 1 FROM proje_uyeleri pu_erisim WHERE pu_erisim.proje_id = p.id AND pu_erisim.kullanici_id = ?))',
);
$parametreler = array($kullanici_id, $kullanici_id);

if ($filtre_q !== '') {
    $kosullar[] = '(p.ad LIKE ? OR p.kod LIKE ? OR p.davet_kodu LIKE ? OR p.aciklama LIKE ?)';
    $arama = '%' . $filtre_q . '%';
    $parametreler[] = $arama;
    $parametreler[] = $arama;
    $parametreler[] = $arama;
    $parametreler[] = $arama;
}
if (in_array($filtre_durum, array('aktif', 'tamamlandi', 'arsiv'), true)) {
    $kosullar[] = 'p.durum = ?';
    $parametreler[] = $filtre_durum;
}

$sql = '
    SELECT p.*, u.ad_soyad AS yonetici_adi,
           (SELECT COUNT(*) FROM proje_uyeleri pu WHERE pu.proje_id = p.id) AS uye_sayisi,
           (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id = p.id) AS toplam_gorev,
           (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id = p.id AND g.durum = \'tamamlandi\') AS biten_gorev
    FROM projeler p
    INNER JOIN kullanicilar u ON u.id = p.yonetici_id
';
if (!empty($kosullar)) {
    $sql .= ' WHERE ' . implode(' AND ', $kosullar);
}
$sql .= ' ORDER BY p.olusturma_tarihi DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametreler);
$projeler = $stmt->fetchAll();

// Takim uyeleri ve gorev yuku ozeti (Siki oda izolasyonu: yalnizca kullanicinin erisebildigi odalardaki uyeler ve gorevler)
$takim_uyeleri = array();
$proje_gorev_haritasi = array();
$erisim_proje_stmt = $pdo->prepare('
    SELECT p.id, p.kod, p.ad
    FROM projeler p
    WHERE p.yonetici_id = ?
       OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
');
$erisim_proje_stmt->execute(array($kullanici_id, $kullanici_id));
$erisim_projeleri = $erisim_proje_stmt->fetchAll();

if (!empty($erisim_projeleri)) {
    $erisim_idleri = array();
    $erisim_kod_haritasi = array();
    foreach ($erisim_projeleri as $ep) {
        $eid = (int) $ep['id'];
        $erisim_idleri[] = $eid;
        $erisim_kod_haritasi[$eid] = $ep['kod'];
    }
    $yer_tutucu = implode(',', array_fill(0, count($erisim_idleri), '?'));

    // Odalardaki uyeleri cek
    $uye_sorgu = $pdo->prepare('
        SELECT DISTINCT u.id AS kullanici_id, u.ad_soyad, u.unvan, u.rol, pu.proje_id, pu.yetki
        FROM proje_uyeleri pu
        INNER JOIN kullanicilar u ON u.id = pu.kullanici_id
        WHERE pu.proje_id IN (' . $yer_tutucu . ')
        ORDER BY u.ad_soyad ASC
    ');
    $uye_sorgu->execute($erisim_idleri);
    $uye_satirlari = $uye_sorgu->fetchAll();

    foreach ($uye_satirlari as $us) {
        $uid = (int) $us['kullanici_id'];
        $pid = (int) $us['proje_id'];
        if (!isset($takim_uyeleri[$uid])) {
            $takim_uyeleri[$uid] = array(
                'kullanici_id' => $uid,
                'ad_soyad' => $us['ad_soyad'],
                'unvan' => $us['unvan'],
                'rol' => $us['rol'],
                'odalar' => array(),
                'oda_sayisi' => 0,
                'toplam_gorev' => 0,
                'aktif_gorev' => 0,
                'biten_gorev' => 0,
                'tamamlanma_orani' => 0,
            );
        }
        if (isset($erisim_kod_haritasi[$pid]) && !in_array($erisim_kod_haritasi[$pid], $takim_uyeleri[$uid]['odalar'], true)) {
            $takim_uyeleri[$uid]['odalar'][] = $erisim_kod_haritasi[$pid];
            $takim_uyeleri[$uid]['oda_sayisi'] = count($takim_uyeleri[$uid]['odalar']);
        }
    }

    // Erisilen odalardaki tum gorevleri alt gorev ilerlemeleriyle cek
    $gorev_ilerleme_sorgu = $pdo->prepare('
        SELECT g.id, g.proje_id, g.atanan_id, g.durum,
               (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id) AS alt_toplam,
               (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id AND ag.tamamlandi = 1) AS alt_tamamlanan
        FROM gorevler g
        WHERE g.proje_id IN (' . $yer_tutucu . ')
    ');
    $gorev_ilerleme_sorgu->execute($erisim_idleri);
    $uye_gorev_haritasi = array();
    foreach ($gorev_ilerleme_sorgu->fetchAll() as $gs) {
        $pid_key = (int) $gs['proje_id'];
        if (!isset($proje_gorev_haritasi[$pid_key])) {
            $proje_gorev_haritasi[$pid_key] = array();
        }
        $proje_gorev_haritasi[$pid_key][] = $gs;

        if ($gs['atanan_id'] !== null) {
            $uid = (int) $gs['atanan_id'];
            if (isset($takim_uyeleri[$uid])) {
                $takim_uyeleri[$uid]['toplam_gorev']++;
                if ($gs['durum'] === 'tamamlandi') {
                    $takim_uyeleri[$uid]['biten_gorev']++;
                } else {
                    $takim_uyeleri[$uid]['aktif_gorev']++;
                }
                if (!isset($uye_gorev_haritasi[$uid])) {
                    $uye_gorev_haritasi[$uid] = array();
                }
                $uye_gorev_haritasi[$uid][] = $gs;
            }
        }
    }

    foreach ($takim_uyeleri as $uid => $tu_bilgi) {
        $uye_gorevleri = isset($uye_gorev_haritasi[$uid]) ? $uye_gorev_haritasi[$uid] : array();
        $takim_uyeleri[$uid]['tamamlanma_orani'] = gorev_listesi_ortalama_ilerleme($uye_gorevleri);
    }
    $takim_uyeleri = array_values($takim_uyeleri);
}

$sayfa_basligi = 'Topluluk / Çalışma Odaları';
$aktif_sayfa = 'projeler';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Topluluk / Çalışma Odaları</h1>
        <p class="page-subtitle">Topluluğunuz adına çalışma odası oluşturun, ekibinizi davet koduyla toplayın ve yıllık görevleri yönetin.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" data-modal-open="modal-koda-katil">Kod ile Odaya Katıl</button>
        <button type="button" class="btn btn-primary" data-modal-open="modal-yeni-proje">+ Yeni Topluluk Odası</button>
    </div>
</div>

<div class="join-room-bar">
    <div class="join-room-info">
        <div class="join-room-title">Kod ile Odaya Katıl</div>
        <div class="join-room-sub">Ekip arkadaşınızdan aldığınız oda davet kodunu girerek doğrudan çalışma alanına katılın.</div>
    </div>
    <form method="post" action="projeler.php" class="join-room-form">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="islem" value="koda_katil">
        <input type="text"
               name="oda_kodu"
               class="form-control join-room-input"
               placeholder="Örn: ODA-7K4M9P"
               aria-label="Oda davet kodu"
               autocomplete="off"
               required>
        <button type="submit" class="btn btn-primary">Katıl</button>
    </form>
</div>

<form method="get" action="projeler.php" class="filter-bar">
    <div class="filter-search">
        <input type="search"
               name="q"
               class="form-control"
               placeholder="Proje adı veya kodu ara..."
               value="<?php echo e($filtre_q); ?>"
               data-filter-search>
    </div>
    <select name="durum" class="form-control" aria-label="Durum filtresi" data-filter-status>
        <option value="">Tüm Durumlar</option>
        <option value="aktif"<?php echo $filtre_durum === 'aktif' ? ' selected' : ''; ?>>Aktif</option>
        <option value="tamamlandi"<?php echo $filtre_durum === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
        <option value="arsiv"<?php echo $filtre_durum === 'arsiv' ? ' selected' : ''; ?>>Arşiv</option>
    </select>
    <button type="submit" class="btn btn-secondary">Filtrele</button>
    <?php if ($filtre_q !== '' || $filtre_durum !== ''): ?>
        <a href="projeler.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($projeler)): ?>
    <div class="card empty-state">
        <?php echo bos_durum_gorseli('oda'); ?>
        <p>Kriterlere uyan proje bulunamadı.</p>
    </div>
<?php else: ?>
    <div class="grid-3">
        <?php foreach ($projeler as $proje): ?>
            <?php
            $pdurum = proje_durum_etiketi($proje['durum']);
            $duzenleyebilir = $yonetici || ((int) $proje['yonetici_id'] === $kullanici_id);
            $toplam_g = (int) $proje['toplam_gorev'];
            $biten_g = (int) $proje['biten_gorev'];
            $pid_key = (int) $proje['id'];
            $oda_gorevleri = isset($proje_gorev_haritasi[$pid_key]) ? $proje_gorev_haritasi[$pid_key] : array();
            $ilerleme_yuzde = gorev_listesi_ortalama_ilerleme($oda_gorevleri);
            $renk_idx = (int) $proje['id'] % 6;
            ?>
            <article class="room-card"
                     data-filter-item
                     data-status="<?php echo e($proje['durum']); ?>"
                     data-search-text="<?php echo e($proje['kod'] . ' ' . $proje['davet_kodu'] . ' ' . $proje['ad'] . ' ' . (string) $proje['aciklama']); ?>">
                <div class="room-card-top">
                    <div style="display:flex;align-items:center;gap:0.7rem;min-width:0;flex:1;">
                        <span class="room-card-icon" aria-hidden="true"><?php echo e(bas_harfler($proje['ad'])); ?></span>
                        <div style="min-width:0;">
                            <h2 class="section-title" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <a href="proje_detay.php?id=<?php echo (int) $proje['id']; ?>" style="color:inherit;"><?php echo e($proje['ad']); ?></a>
                            </h2>
                            <div class="stat-meta" style="display:flex;align-items:center;gap:0.4rem;">
                                <span class="room-dot room-dot-<?php echo $renk_idx; ?>" aria-hidden="true"></span>
                                <span class="mono"><?php echo e($proje['kod']); ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="badge <?php echo e($pdurum['sinif']); ?>"><?php echo e($pdurum['metin']); ?></span>
                </div>

                <p class="text-muted" style="font-size:0.84rem;margin:0;">
                    <?php echo !empty($proje['aciklama']) && trim((string) $proje['aciklama']) !== '' ? e(metin_kisalt($proje['aciklama'], 110)) : 'Açıklama eklenmemiş.'; ?>
                </p>

                <div class="room-card-stats">
                    <div class="room-stat-box">
                        <span>Üye</span>
                        <strong><?php echo (int) $proje['uye_sayisi']; ?></strong>
                    </div>
                    <div class="room-stat-box">
                        <span>Görev</span>
                        <strong><?php echo $biten_g; ?>/<?php echo $toplam_g; ?></strong>
                    </div>
                </div>

                <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.76rem;margin-bottom:0.3rem;">
                        <span class="text-muted">İlerleme</span>
                        <span class="mono" style="font-weight:700;color:var(--accent);">%<?php echo $ilerleme_yuzde; ?></span>
                    </div>
                    <div class="progress-bar" aria-label="Oda ilerlemesi %<?php echo $ilerleme_yuzde; ?>">
                        <div class="progress-fill" style="width: <?php echo $ilerleme_yuzde; ?>%;"></div>
                    </div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                    <span class="room-code-chip">
                        <span class="code-chip"><?php echo e($proje['davet_kodu']); ?></span>
                        <button type="button"
                                class="room-code-copy-btn"
                                data-copy-text="<?php echo e($proje['davet_kodu']); ?>"
                                title="Oda kodunu kopyala">Kopyala</button>
                    </span>
                    <span class="date-muted"><?php echo e(tarih_format($proje['bitis_tarihi'])); ?></span>
                </div>

                <div class="room-card-foot">
                    <a href="proje_detay.php?id=<?php echo (int) $proje['id']; ?>" class="btn btn-primary btn-sm">Odaya Git &rarr;</a>
                    <?php if ($duzenleyebilir): ?>
                        <div style="display:inline-flex;gap:0.25rem;">
                            <button type="button" class="btn btn-ghost btn-sm" data-modal-open="modal-proje-duzenle-<?php echo (int) $proje['id']; ?>">Düzenle</button>
                            <form method="post" action="projeler.php" style="display:inline;" data-confirm="Bu odayı ve tüm görevlerini silmek istediğinize emin misiniz?">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                <input type="hidden" name="islem" value="proje_sil">
                                <input type="hidden" name="proje_id" value="<?php echo (int) $proje['id']; ?>">
                                <button type="submit" class="btn btn-ghost btn-sm text-danger">Sil</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <?php if ($duzenleyebilir): ?>
                <dialog id="modal-proje-duzenle-<?php echo (int) $proje['id']; ?>" class="modal-dialog">
                    <div class="modal-header">
                        <h3 class="modal-title">Projeyi Düzenle</h3>
                        <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
                    </div>
                    <form method="post" action="projeler.php" data-validate-form>
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                        <input type="hidden" name="islem" value="proje_guncelle">
                        <input type="hidden" name="proje_id" value="<?php echo (int) $proje['id']; ?>">
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="ad-<?php echo (int) $proje['id']; ?>">Proje Adı</label>
                                    <input type="text" id="ad-<?php echo (int) $proje['id']; ?>" name="ad" class="form-control" value="<?php echo e($proje['ad']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="kod-<?php echo (int) $proje['id']; ?>">Proje Kodu</label>
                                    <input type="text" id="kod-<?php echo (int) $proje['id']; ?>" name="kod" class="form-control mono" value="<?php echo e($proje['kod']); ?>" maxlength="24" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="aciklama-<?php echo (int) $proje['id']; ?>">Açıklama</label>
                                <textarea id="aciklama-<?php echo (int) $proje['id']; ?>" name="aciklama" class="form-control" rows="3"><?php echo e($proje['aciklama']); ?></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="durum-<?php echo (int) $proje['id']; ?>">Durum</label>
                                    <select id="durum-<?php echo (int) $proje['id']; ?>" name="durum" class="form-control">
                                        <option value="aktif"<?php echo $proje['durum'] === 'aktif' ? ' selected' : ''; ?>>Aktif</option>
                                        <option value="tamamlandi"<?php echo $proje['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                                        <option value="arsiv"<?php echo $proje['durum'] === 'arsiv' ? ' selected' : ''; ?>>Arşiv</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="baslangic-<?php echo (int) $proje['id']; ?>">Başlangıç</label>
                                    <input type="date" id="baslangic-<?php echo (int) $proje['id']; ?>" name="baslangic_tarihi" class="form-control" value="<?php echo e($proje['baslangic_tarihi']); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="bitis-<?php echo (int) $proje['id']; ?>">Bitiş</label>
                                    <input type="date" id="bitis-<?php echo (int) $proje['id']; ?>" name="bitis_tarihi" class="form-control" value="<?php echo e($proje['bitis_tarihi']); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
                            <button type="submit" class="btn btn-primary">Kaydet</button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="card empty-state" data-filter-empty style="display:none;">
        <?php echo bos_durum_gorseli('oda'); ?>
        <p>Filtreye uygun oda bulunamadı.</p>
    </div>
<?php endif; ?>

<?php if (!empty($takim_uyeleri)): ?>
    <section class="card card-flush" style="margin-top:1.75rem;">
        <div class="card-header">
            <h2 class="section-title">Takım Üyeleri ve Görev Yükü (<?php echo count($takim_uyeleri); ?>)</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Üye</th>
                        <th>Unvan</th>
                        <th>Dahil Olduğu Odalar</th>
                        <th>Aktif Görev</th>
                        <th>Tamamlanma Oranı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($takim_uyeleri as $tu): ?>
                        <?php $oran = (int) $tu['tamamlanma_orani']; ?>
                        <tr>
                            <td data-label="Üye">
                                <span class="assignee-cell">
                                    <span class="avatar avatar-sm <?php echo e(avatar_renk_sinifi($tu['ad_soyad'])); ?>"><?php echo e(bas_harfler($tu['ad_soyad'])); ?></span>
                                    <strong><?php echo e($tu['ad_soyad']); ?></strong>
                                </span>
                            </td>
                            <td data-label="Unvan" class="text-muted"><?php echo e($tu['unvan']); ?></td>
                            <td data-label="Dahil Olduğu Odalar">
                                <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                                    <?php foreach ($tu['odalar'] as $oda_kod): ?>
                                        <span class="code-chip"><?php echo e($oda_kod); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td data-label="Aktif Görev" class="mono"><?php echo (int) $tu['aktif_gorev']; ?> aktif / <?php echo (int) $tu['toplam_gorev']; ?> toplam</td>
                            <td data-label="Tamamlanma Oranı">
                                <div class="inline-progress inline-progress-wide">
                                    <div class="inline-progress-bar">
                                        <div class="inline-progress-fill<?php echo $oran >= 100 ? ' is-complete' : ''; ?>" style="width: <?php echo $oran; ?>%;"></div>
                                    </div>
                                    <span class="inline-progress-val">%<?php echo $oran; ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<dialog id="modal-yeni-proje" class="modal-dialog">
    <div class="modal-header">
        <h3 class="modal-title">Yeni Topluluk Odası Oluştur</h3>
        <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
    </div>
    <form method="post" action="projeler.php" data-validate-form>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="islem" value="olustur">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="yeni-ad">Topluluk / Oda Adı</label>
                        <input type="text" id="yeni-ad" name="ad" class="form-control" placeholder="Örn: Yazılım Topluluğu 2026" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="yeni-kod">Kısa Kod (İsteğe bağlı)</label>
                        <input type="text" id="yeni-kod" name="kod" class="form-control mono" placeholder="Boşsa otomatik üretilir" maxlength="24">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="yeni-aciklama">Açıklama</label>
                    <textarea id="yeni-aciklama" name="aciklama" class="form-control" rows="3" placeholder="Topluluğun veya çalışma odasının yıllık hedefini kısaca özetleyin."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="yeni-durum">Durum</label>
                        <select id="yeni-durum" name="durum" class="form-control">
                            <option value="aktif">Aktif</option>
                            <option value="tamamlandi">Tamamlandı</option>
                            <option value="arsiv">Arşiv</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="yeni-baslangic">Başlangıç Tarihi</label>
                        <input type="date" id="yeni-baslangic" name="baslangic_tarihi" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="yeni-bitis">Hedef Bitiş</label>
                        <input type="date" id="yeni-bitis" name="bitis_tarihi" class="form-control">
                    </div>
                </div>
            </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
            <button type="submit" class="btn btn-primary">Topluluk Odası Oluştur</button>
        </div>
    </form>
</dialog>

<dialog id="modal-koda-katil" class="modal-dialog">
    <div class="modal-header">
        <h3 class="modal-title">Kod ile Odaya Katıl</h3>
        <button type="button" class="modal-close" data-modal-close aria-label="Kapat">&times;</button>
    </div>
    <form method="post" action="projeler.php" data-validate-form>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="islem" value="koda_katil">
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="modal-oda-kodu">Grup / Oda Davet Kodu</label>
                <input type="text"
                       id="modal-oda-kodu"
                       name="oda_kodu"
                       class="form-control mono"
                       placeholder="Örn: ODA-7K4M9P"
                       autocomplete="off"
                       required>
                <span class="form-hint">Grup yöneticisinden aldığınız davet kodunu girin; anında odaya dahil olursunuz.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
            <button type="submit" class="btn btn-primary">Odaya Katıl</button>
        </div>
    </form>
</dialog>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
