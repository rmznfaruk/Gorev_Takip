<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$yonetici = yonetici_mi($kullanici);
$csrf = csrf_token();

$gorunum = isset($_GET['gorunum']) && $_GET['gorunum'] === 'benim' ? 'benim' : 'tumu';
$filtre_q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filtre_durum = isset($_GET['durum']) ? trim((string) $_GET['durum']) : '';
$filtre_oncelik = isset($_GET['oncelik']) ? trim((string) $_GET['oncelik']) : '';
$filtre_birim = isset($_GET['birim']) ? birim_kodu_normalize($_GET['birim'], '') : '';
$filtre_proje = isset($_GET['proje_id']) ? (int) $_GET['proje_id'] : 0;

$birimler = birim_secenekleri();

// Oda izolasyonu: Yalnizca kullanicinin yonetici veya uye oldugu odalardaki gorevler
$kosullar = array(
    '(p.yonetici_id = ? OR EXISTS (SELECT 1 FROM proje_uyeleri pu_erisim WHERE pu_erisim.proje_id = p.id AND pu_erisim.kullanici_id = ?))',
);
$parametreler = array($kullanici_id, $kullanici_id);

if ($gorunum === 'benim') {
    $kosullar[] = 'g.atanan_id = ?';
    $parametreler[] = $kullanici_id;
}
if ($filtre_q !== '') {
    $kosullar[] = '(g.baslik LIKE ? OR g.aciklama LIKE ? OR p.kod LIKE ? OR p.ad LIKE ? OR u.ad_soyad LIKE ?)';
    $arama = '%' . $filtre_q . '%';
    $parametreler[] = $arama;
    $parametreler[] = $arama;
    $parametreler[] = $arama;
    $parametreler[] = $arama;
    $parametreler[] = $arama;
}
if (in_array($filtre_durum, array('yapilacak', 'devam_ediyor', 'tamamlandi'), true)) {
    $kosullar[] = 'g.durum = ?';
    $parametreler[] = $filtre_durum;
}
if (in_array($filtre_oncelik, array('dusuk', 'orta', 'yuksek'), true)) {
    $kosullar[] = 'g.oncelik = ?';
    $parametreler[] = $filtre_oncelik;
}
if ($filtre_birim !== '') {
    $birim_degerleri = birim_filtre_degerleri($filtre_birim);
    $birim_ph = implode(',', array_fill(0, count($birim_degerleri), '?'));
    $kosullar[] = 'g.birim IN (' . $birim_ph . ')';
    $parametreler = array_merge($parametreler, $birim_degerleri);
}
if ($filtre_proje > 0) {
    $kosullar[] = 'g.proje_id = ?';
    $parametreler[] = $filtre_proje;
}

$sql = '
    SELECT g.*, p.ad AS proje_adi, p.kod AS proje_kodu, p.yonetici_id AS proje_yonetici_id,
           u.ad_soyad AS atanan_adi,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id) AS alt_toplam,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id AND ag.tamamlandi = 1) AS alt_tamamlanan
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u ON u.id = g.atanan_id
';
if (!empty($kosullar)) {
    $sql .= ' WHERE ' . implode(' AND ', $kosullar);
}
$sql .= '
    ORDER BY
        CASE g.durum WHEN \'devam_ediyor\' THEN 1 WHEN \'yapilacak\' THEN 2 ELSE 3 END,
        CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END,
        g.olusturma_tarihi DESC
';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametreler);
$gorevler = $stmt->fetchAll();
foreach ($gorevler as $idx => $g) {
    $gorevler[$idx]['ilerleme'] = gorev_ilerleme_hesapla($g, $g['alt_toplam'], $g['alt_tamamlanan']);
    $gorevler[$idx]['teslim_durumu'] = teslim_durumu_hesapla($g['son_tarih'], $g['durum']);
}

// Proje filtresi listesi de yalnizca kullanicinin erisebildigi odalari icerir
$proje_stmt = $pdo->prepare('
    SELECT p.id, p.kod, p.ad
    FROM projeler p
    WHERE p.yonetici_id = ?
       OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
    ORDER BY p.ad ASC
');
$proje_stmt->execute(array($kullanici_id, $kullanici_id));
$proje_listesi = $proje_stmt->fetchAll();

$sayfa_basligi = 'Görevler';
$aktif_sayfa = 'gorevler';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Görevler</h1>
        <p class="page-subtitle">Proje görevlerini filtreleyin ve durumlarını güncelleyin.</p>
    </div>
    <div class="page-actions">
        <a href="projeler.php" class="btn btn-primary">Projeler</a>
    </div>
</div>

<div class="tabs" role="tablist">
    <a href="gorevler.php?gorunum=tumu" class="tab-link<?php echo $gorunum === 'tumu' ? ' active' : ''; ?>">Tüm Görevler</a>
    <a href="gorevler.php?gorunum=benim" class="tab-link<?php echo $gorunum === 'benim' ? ' active' : ''; ?>">Bana Atananlar</a>
</div>

<form method="get" action="gorevler.php" class="filter-bar">
    <input type="hidden" name="gorunum" value="<?php echo e($gorunum); ?>">
    <div class="filter-search">
        <input type="search"
               name="q"
               class="form-control"
               placeholder="Görev başlığı, açıklama veya proje kodu ara..."
               value="<?php echo e($filtre_q); ?>"
               data-filter-search>
    </div>
    <select name="proje_id" class="form-control" aria-label="Proje filtresi">
        <option value="0">Tüm Projeler</option>
        <?php foreach ($proje_listesi as $pr): ?>
            <option value="<?php echo (int) $pr['id']; ?>"<?php echo $filtre_proje === (int) $pr['id'] ? ' selected' : ''; ?>>
                <?php echo e($pr['kod'] . ' — ' . $pr['ad']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="durum" class="form-control" aria-label="Durum filtresi" data-filter-status>
        <option value="">Tüm Durumlar</option>
        <option value="yapilacak"<?php echo $filtre_durum === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
        <option value="devam_ediyor"<?php echo $filtre_durum === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
        <option value="tamamlandi"<?php echo $filtre_durum === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
    </select>
    <select name="oncelik" class="form-control" aria-label="Öncelik filtresi" data-filter-priority>
        <option value="">Tüm Öncelikler</option>
        <option value="yuksek"<?php echo $filtre_oncelik === 'yuksek' ? ' selected' : ''; ?>>Yüksek</option>
        <option value="orta"<?php echo $filtre_oncelik === 'orta' ? ' selected' : ''; ?>>Orta</option>
        <option value="dusuk"<?php echo $filtre_oncelik === 'dusuk' ? ' selected' : ''; ?>>Düşük</option>
    </select>
    <select name="birim" class="form-control" aria-label="Birim filtresi" data-filter-unit>
        <option value="">Tüm Birimler</option>
        <?php foreach ($birimler as $birim_kod => $birim_ad): ?>
            <option value="<?php echo e($birim_kod); ?>"<?php echo $filtre_birim === $birim_kod ? ' selected' : ''; ?>>
                <?php echo e($birim_ad); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary">Filtrele</button>
    <?php if ($filtre_q !== '' || $filtre_durum !== '' || $filtre_oncelik !== '' || $filtre_birim !== '' || $filtre_proje > 0): ?>
        <a href="gorevler.php?gorunum=<?php echo e($gorunum); ?>" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<section class="card card-flush">
    <?php if (empty($gorevler)): ?>
        <div class="empty-state">
            <?php echo bos_durum_gorseli('gorev'); ?>
            <p>Seçilen filtrelere uygun görev bulunamadı.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Görev</th>
                        <th>Proje</th>
                        <th>Birim</th>
                        <th>Atanan</th>
                        <th>Öncelik</th>
                        <th>Son Tarih</th>
                        <th>Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gorevler as $gorev): ?>
                        <?php
                        $durum = durum_etiketi($gorev['durum']);
                        $oncelik = oncelik_etiketi($gorev['oncelik']);
                        $birim_bilgi = birim_etiketi(isset($gorev['birim']) ? $gorev['birim'] : 'genel');
                        $gorev_birim_kodu = $birim_bilgi['kod'];
                        $durum_sinifi = 'status-' . $gorev['durum'];
                        $degistirebilir = proje_yonetebilir_mi(array('yonetici_id' => $gorev['proje_yonetici_id']), $kullanici)
                            || ($gorev['atanan_id'] !== null && (int) $gorev['atanan_id'] === $kullanici_id);
                        ?>
                        <tr data-filter-item
                            data-status="<?php echo e($gorev['durum']); ?>"
                            data-priority="<?php echo e($gorev['oncelik']); ?>"
                            data-unit="<?php echo e($gorev_birim_kodu); ?>"
                            data-search-text="<?php echo e($gorev['baslik'] . ' ' . $gorev['proje_kodu'] . ' ' . $gorev['proje_adi'] . ' ' . (string) $gorev['atanan_adi'] . ' ' . $birim_bilgi['metin']); ?>">
                            <td data-label="Görev">
                                <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>" class="task-title-link">
                                    <span class="task-id-muted">#<?php echo (int) $gorev['id']; ?></span>
                                    <span><?php echo e($gorev['baslik']); ?></span>
                                </a>
                            </td>
                            <td data-label="Proje">
                                <a href="proje_detay.php?id=<?php echo (int) $gorev['proje_id']; ?>" class="code-chip" title="<?php echo e($gorev['proje_adi']); ?>">
                                    <?php echo e($gorev['proje_kodu']); ?>
                                </a>
                            </td>
                            <td data-label="Birim">
                                <span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo birim_ikonu(isset($gorev['birim']) ? $gorev['birim'] : 'genel'); ?><span><?php echo e($birim_bilgi['metin']); ?></span></span>
                            </td>
                            <td data-label="Atanan">
                                <?php if ($gorev['atanan_adi']): ?>
                                    <span class="assignee-cell">
                                        <span class="avatar-xs <?php echo e(avatar_renk_sinifi($gorev['atanan_adi'])); ?>" aria-hidden="true"><?php echo e(bas_harfler($gorev['atanan_adi'])); ?></span>
                                        <span><?php echo e($gorev['atanan_adi']); ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="assignee-unassigned">Atanmadı</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Öncelik">
                                <span class="priority-indicator priority-<?php echo e($gorev['oncelik']); ?>">
                                    <span class="priority-dot" aria-hidden="true"></span>
                                    <span><?php echo e($oncelik['metin']); ?></span>
                                </span>
                            </td>
                            <td data-label="Son Tarih"><?php echo son_tarih_aciliyet_html($gorev['son_tarih'], $gorev['durum'] === 'tamamlandi'); ?></td>
                            <td data-label="Durum">
                                <?php if ($degistirebilir): ?>
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
                                    <span class="status-pill <?php echo e($durum_sinifi); ?>" data-task-badge="<?php echo (int) $gorev['id']; ?>">
                                        <span class="status-dot" aria-hidden="true"></span>
                                        <span class="status-pill-text"><?php echo e($durum['metin']); ?></span>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="empty-state" data-filter-empty style="display:none;">
            <?php echo bos_durum_gorseli('gorev'); ?>
            <p>Arama kriterine uyan görev bulunamadı.</p>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
