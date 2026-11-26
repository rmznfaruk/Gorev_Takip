<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$ekip_lideri = kullanici_ekip_lideri_mi($pdo, $kullanici);
$csrf = csrf_token();

$sayfa_basligi = 'Genel Bakış';
$aktif_sayfa = 'panel';

// Ozet istatistikler (yalnizca kullanicinin yonetici veya uye oldugu odalar)
$stmt = $pdo->prepare('
    SELECT COUNT(DISTINCT p.id)
    FROM projeler p
    WHERE p.yonetici_id = ?
       OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
');
$stmt->execute(array($kullanici_id, $kullanici_id));
$proje_sayisi = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT g.durum, COUNT(*) AS adet
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE g.atanan_id = ?
      AND (
          p.yonetici_id = ?
          OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
      )
    GROUP BY g.durum
');
$stmt->execute(array($kullanici_id, $kullanici_id, $kullanici_id));
$durum_sayilari = array('yapilacak' => 0, 'devam_ediyor' => 0, 'tamamlandi' => 0);
foreach ($stmt->fetchAll() as $satir) {
    if (isset($durum_sayilari[$satir['durum']])) {
        $durum_sayilari[$satir['durum']] = (int) $satir['adet'];
    }
}
$toplam_atanan = $durum_sayilari['yapilacak'] + $durum_sayilari['devam_ediyor'] + $durum_sayilari['tamamlandi'];

// Kullanicinin erisebildigi tum odalardaki genel gorev istatistikleri (alt gorevler dahil gercek ortalama ilerleme)
$stmt = $pdo->prepare('
    SELECT g.id, g.proje_id, g.durum,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id) AS alt_toplam,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id AND ag.tamamlandi = 1) AS alt_tamamlanan
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE p.yonetici_id = ?
       OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
');
$stmt->execute(array($kullanici_id, $kullanici_id));
$erisim_gorevleri = $stmt->fetchAll();

$genel_durum_sayilari = array('yapilacak' => 0, 'devam_ediyor' => 0, 'tamamlandi' => 0);
$toplam_alt_gorev = 0;
$toplam_alt_tamamlanan = 0;
$proje_gorev_haritasi = array();
foreach ($erisim_gorevleri as $eg) {
    if (isset($genel_durum_sayilari[$eg['durum']])) {
        $genel_durum_sayilari[$eg['durum']]++;
    }
    $toplam_alt_gorev += (int) $eg['alt_toplam'];
    $toplam_alt_tamamlanan += (int) $eg['alt_tamamlanan'];
    $pid_key = (int) $eg['proje_id'];
    if (!isset($proje_gorev_haritasi[$pid_key])) {
        $proje_gorev_haritasi[$pid_key] = array();
    }
    $proje_gorev_haritasi[$pid_key][] = $eg;
}
$genel_toplam_gorev = count($erisim_gorevleri);
$genel_ilerleme_yuzde = gorev_listesi_ortalama_ilerleme($erisim_gorevleri);

// Yaklasan ve geciken teslim tarihleri (yalnizca kullanicinin kendi ana gorevleri + kendi haftalik alt gorevleri)
$stmt = $pdo->prepare('
    SELECT g.id, g.baslik, g.durum, g.oncelik, g.birim, g.son_tarih,
           p.id AS proje_id, p.ad AS proje_adi, p.kod AS proje_kodu
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE g.durum <> ?
      AND g.atanan_id = ?
      AND g.son_tarih IS NOT NULL
      AND g.son_tarih <> \'\'
      AND (
          p.yonetici_id = ?
          OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
      )
    ORDER BY g.son_tarih ASC, CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END
');
$stmt->execute(array('tamamlandi', $kullanici_id, $kullanici_id, $kullanici_id));
$kisisel_ana_gorevler = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT ag.id AS alt_id, ag.gorev_id, ag.hafta_etiketi, ag.baslik, ag.son_tarih, ag.sira,
           g.baslik AS ust_gorev_baslik, g.son_tarih AS ust_son_tarih, g.oncelik,
           p.id AS proje_id, p.ad AS proje_adi, p.kod AS proje_kodu
    FROM alt_gorevler ag
    INNER JOIN gorevler g ON g.id = ag.gorev_id
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE ag.tamamlandi = 0
      AND g.durum <> ?
      AND (ag.atanan_id = ? OR (ag.atanan_id IS NULL AND g.atanan_id = ?))
      AND (
          p.yonetici_id = ?
          OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
      )
    ORDER BY ag.sira ASC, ag.id ASC
');
$stmt->execute(array('tamamlandi', $kullanici_id, $kullanici_id, $kullanici_id, $kullanici_id));
$kisisel_alt_gorevler = $stmt->fetchAll();

$yaklasan_gorevler = array();

foreach ($kisisel_alt_gorevler as $ag) {
    $ham_alt_tarih = isset($ag['son_tarih']) ? trim((string) $ag['son_tarih']) : '';
    if (strpos($ham_alt_tarih, '0000-00-00') === 0) {
        $ham_alt_tarih = '';
    }
    $ham_ust_tarih = isset($ag['ust_son_tarih']) ? trim((string) $ag['ust_son_tarih']) : '';
    if (strpos($ham_ust_tarih, '0000-00-00') === 0) {
        $ham_ust_tarih = '';
    }
    $kendi_tarihi_var = ($ham_alt_tarih !== '');
    $etkin_tarih = $kendi_tarihi_var ? $ham_alt_tarih : $ham_ust_tarih;
    $hafta_etiketi = trim((string) $ag['hafta_etiketi']) !== '' ? trim((string) $ag['hafta_etiketi']) : 'Haftalık';

    $yaklasan_gorevler[] = array(
        'tur' => 'haftalik',
        'hedef_link' => 'gorev_detay.php?id=' . (int) $ag['gorev_id'] . '#haftalik-plan',
        'baslik' => $ag['baslik'],
        'etiket' => $hafta_etiketi,
        'kisa_etiket' => kisa_hafta_etiketi($hafta_etiketi),
        'alt_bilgi' => $ag['proje_adi'] . ' · ' . metin_kisalt($ag['ust_gorev_baslik'], 40),
        'son_tarih' => $etkin_tarih,
        'kendi_tarihi_var' => $kendi_tarihi_var,
        'durum' => 'yapilacak',
        'sira' => (int) $ag['sira'],
        'id_sort' => (int) $ag['alt_id'],
        'teslim_durumu' => $kendi_tarihi_var ? teslim_durumu_hesapla($etkin_tarih, 'yapilacak') : array('durum' => 'normal', 'kisa' => 'Haftalık'),
    );
}

foreach ($kisisel_ana_gorevler as $yg) {
    $ana_tarih = trim((string) $yg['son_tarih']);
    if ($ana_tarih === '' || strpos($ana_tarih, '0000-00-00') === 0) {
        continue;
    }
    $yaklasan_gorevler[] = array(
        'tur' => 'ana_gorev',
        'hedef_link' => 'gorev_detay.php?id=' . (int) $yg['id'],
        'baslik' => $yg['baslik'],
        'etiket' => 'Ana Görev',
        'kisa_etiket' => '',
        'alt_bilgi' => $yg['proje_adi'],
        'son_tarih' => $ana_tarih,
        'kendi_tarihi_var' => true,
        'durum' => $yg['durum'],
        'sira' => 9999,
        'id_sort' => (int) $yg['id'],
        'teslim_durumu' => teslim_durumu_hesapla($ana_tarih, $yg['durum']),
    );
}

usort($yaklasan_gorevler, function ($a, $b) {
    $a_tarih = isset($a['son_tarih']) ? substr(trim((string) $a['son_tarih']), 0, 10) : '';
    $b_tarih = isset($b['son_tarih']) ? substr(trim((string) $b['son_tarih']), 0, 10) : '';
    $a_bos = ($a_tarih === '') ? 1 : 0;
    $b_bos = ($b_tarih === '') ? 1 : 0;
    if ($a_bos !== $b_bos) {
        return $a_bos - $b_bos;
    }
    if ($a_tarih !== $b_tarih) {
        $cmp = strcmp($a_tarih, $b_tarih);
        return $cmp < 0 ? -1 : 1;
    }
    $a_tur_sira = ($a['tur'] === 'haftalik') ? 0 : 1;
    $b_tur_sira = ($b['tur'] === 'haftalik') ? 0 : 1;
    if ($a_tur_sira !== $b_tur_sira) {
        return $a_tur_sira - $b_tur_sira;
    }
    $a_sira = (int) $a['sira'];
    $b_sira = (int) $b['sira'];
    if ($a_sira !== $b_sira) {
        return ($a_sira < $b_sira) ? -1 : 1;
    }
    $a_id = (int) $a['id_sort'];
    $b_id = (int) $b['id_sort'];
    if ($a_id !== $b_id) {
        return ($a_id < $b_id) ? -1 : 1;
    }
    return 0;
});

$yaklasan_gorevler = array_slice($yaklasan_gorevler, 0, 8);

// Kullanicinin aktif gorevleri (oda izolasyonlu, alt gorev ilerlemesiyle)
$stmt = $pdo->prepare('
    SELECT g.id, g.baslik, g.durum, g.oncelik, g.birim, g.son_tarih,
           p.id AS proje_id, p.ad AS proje_adi, p.kod AS proje_kodu,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id) AS alt_toplam,
           (SELECT COUNT(*) FROM alt_gorevler ag WHERE ag.gorev_id = g.id AND ag.tamamlandi = 1) AS alt_tamamlanan
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE g.atanan_id = ?
      AND g.durum <> ?
      AND (
          p.yonetici_id = ?
          OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
      )
    ORDER BY
        CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END,
        CASE WHEN g.son_tarih IS NULL THEN 1 ELSE 0 END,
        g.son_tarih ASC
    LIMIT 10
');
$stmt->execute(array($kullanici_id, 'tamamlandi', $kullanici_id, $kullanici_id));
$aktif_gorevler = $stmt->fetchAll();
foreach ($aktif_gorevler as $idx => $ag) {
    $aktif_gorevler[$idx]['ilerleme'] = gorev_ilerleme_hesapla($ag, $ag['alt_toplam'], $ag['alt_tamamlanan']);
    $aktif_gorevler[$idx]['teslim_durumu'] = teslim_durumu_hesapla($ag['son_tarih'], $ag['durum']);
}

// Dahil oldugu projeler / odalar (uye sayisi + gorev sayisi + ilerleme yuzdesi)
$stmt = $pdo->prepare('
    SELECT p.id, p.ad, p.kod, p.davet_kodu, p.durum, p.bitis_tarihi,
           COALESCE(pu.proje_rolu, \'Ekip Lideri\') AS proje_rolu,
           COALESCE(pu.yetki, \'lider\') AS yetki,
           (SELECT COUNT(*) FROM proje_uyeleri pu2 WHERE pu2.proje_id = p.id) AS uye_sayisi,
           (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id = p.id) AS toplam_gorev,
           (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id = p.id AND g.durum = \'tamamlandi\') AS biten_gorev
    FROM projeler p
    LEFT JOIN proje_uyeleri pu ON pu.proje_id = p.id AND pu.kullanici_id = ?
    WHERE p.yonetici_id = ? OR pu.kullanici_id IS NOT NULL
    ORDER BY p.olusturma_tarihi DESC
    LIMIT 6
');
$stmt->execute(array($kullanici_id, $kullanici_id));
$projeler = $stmt->fetchAll();
foreach ($projeler as $idx => $pr) {
    $pid_key = (int) $pr['id'];
    $oda_gorevleri = isset($proje_gorev_haritasi[$pid_key]) ? $proje_gorev_haritasi[$pid_key] : array();
    $projeler[$idx]['ilerleme'] = gorev_listesi_ortalama_ilerleme($oda_gorevleri);
}

// Son yorumlar (yalnizca kullanicinin erisebildigi odalardaki yorumlar)
$stmt = $pdo->prepare('
    SELECT y.id, y.icerik, y.olusturma_tarihi, u.ad_soyad, g.id AS gorev_id, g.baslik AS gorev_baslik, p.kod AS proje_kodu
    FROM yorumlar y
    INNER JOIN kullanicilar u ON u.id = y.kullanici_id
    INNER JOIN gorevler g ON g.id = y.gorev_id
    INNER JOIN projeler p ON p.id = g.proje_id
    WHERE p.yonetici_id = ?
       OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
    ORDER BY y.olusturma_tarihi DESC
    LIMIT 5
');
$stmt->execute(array($kullanici_id, $kullanici_id));
$son_yorumlar = $stmt->fetchAll();

$donut_yaricap = 58;
$donut_cevre = 2 * M_PI * $donut_yaricap;
$donut_offset = $donut_cevre * (1 - ($genel_ilerleme_yuzde / 100));
$kisa_aylar = array(
    1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis',
    5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu',
    9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara',
);

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title" style="display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap;">
            <?php echo kullanici_avatar_html($kullanici['ad_soyad'], 'avatar'); ?>
            <span>Merhaba, <?php echo e($kullanici['ad_soyad']); ?></span>
        </h1>
        <p class="page-subtitle">
            <span class="badge <?php echo $ekip_lideri ? 'badge-yonetici' : 'badge-uye'; ?>"><?php echo $ekip_lideri ? 'Ekip Lideri' : 'Üye'; ?></span>
            &nbsp;<?php echo e($kullanici['unvan']); ?> &middot; Çalışma odalarınız ve güncel görev özetiniz.
        </p>
    </div>
    <div class="page-actions">
        <a href="takvim.php" class="btn btn-secondary">Takvim</a>
        <a href="projeler.php" class="btn btn-primary">Çalışma Odaları</a>
    </div>
</div>

<section class="stat-grid" aria-label="Özet istatistikler">
    <div class="stat-card stat-card-primary">
        <span class="stat-label">Toplam Görev</span>
        <span class="stat-value"><?php echo (int) $genel_toplam_gorev; ?></span>
        <span class="stat-meta"><?php echo (int) $proje_sayisi; ?> çalışma odasında</span>
    </div>
    <div class="stat-card stat-card-warning">
        <span class="stat-label">Devam Eden</span>
        <span class="stat-value"><?php echo (int) $genel_durum_sayilari['devam_ediyor']; ?></span>
        <span class="stat-meta">Aktif çalışma sürüyor</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Bekleyen</span>
        <span class="stat-value"><?php echo (int) $genel_durum_sayilari['yapilacak']; ?></span>
        <span class="stat-meta">Başlanmayı bekliyor</span>
    </div>
    <div class="stat-card stat-card-highlight">
        <span class="stat-label">Tamamlanan</span>
        <span class="stat-value"><?php echo (int) $genel_durum_sayilari['tamamlandi']; ?></span>
        <span class="stat-meta">%<?php echo (int) $genel_ilerleme_yuzde; ?> genel tamamlanma</span>
    </div>
</section>

<div class="dashboard-grid">
    <div class="dashboard-main">
        <section>
            <div class="section-header">
                <h2 class="section-title">Aktif Çalışma Odaları</h2>
                <a href="projeler.php" class="btn btn-ghost btn-sm">Tüm Odalar &rarr;</a>
            </div>
            <?php if (empty($projeler)): ?>
                <div class="card empty-state">
                    <?php echo bos_durum_gorseli('oda'); ?>
                    <div class="empty-state-title">Henüz bir çalışma odanız yok</div>
                    <p>Yeni bir oda oluşturabilir veya ekip arkadaşınızdan aldığınız davet koduyla hemen katılabilirsiniz.</p>
                    <div style="margin-top:0.85rem;">
                        <a href="projeler.php" class="btn btn-primary btn-sm">+ Yeni Oda Oluştur</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="room-mini-grid">
                    <?php foreach ($projeler as $proje): ?>
                        <?php
                        $renk_idx = (int) $proje['id'] % 6;
                        $ilerleme = (int) $proje['ilerleme'];
                        $oda_aksan = oda_renk_sinifi($proje['kod']);
                        ?>
                        <a href="proje_detay.php?id=<?php echo (int) $proje['id']; ?>" class="room-mini-card <?php echo e($oda_aksan); ?>">
                            <div class="room-mini-head">
                                <span class="room-mini-title-wrap">
                                    <span class="room-dot room-dot-<?php echo $renk_idx; ?>" aria-hidden="true"></span>
                                    <span class="room-mini-title"><?php echo e($proje['ad']); ?></span>
                                </span>
                                <span class="room-mini-pct">%<?php echo $ilerleme; ?></span>
                            </div>
                            <div class="progress-bar" aria-label="Oda ilerlemesi %<?php echo $ilerleme; ?>">
                                <div class="progress-fill" style="width: <?php echo $ilerleme; ?>%;"></div>
                            </div>
                            <div class="room-mini-meta">
                                <span><?php echo (int) $proje['biten_gorev']; ?>/<?php echo (int) $proje['toplam_gorev']; ?> görev &middot; <?php echo (int) $proje['uye_sayisi']; ?> üye</span>
                                <span class="code-chip"><?php echo e($proje['davet_kodu']); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="join-room-bar" style="margin-bottom:0;">
            <div class="join-room-info">
                <div class="join-room-title">Kod ile Odaya Katıl</div>
                <div class="join-room-sub">Davet kodunuzu girerek doğrudan çalışma odasına katılın.</div>
            </div>
            <form method="post" action="projeler.php" class="join-room-form">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="islem" value="koda_katil">
                <input type="hidden" name="kaynak" value="panel">
                <input type="text"
                       name="oda_kodu"
                       class="form-control join-room-input"
                       placeholder="ODA-7K4M9P"
                       aria-label="Oda davet kodu"
                       autocomplete="off"
                       required>
                <button type="submit" class="btn btn-primary">Katıl</button>
            </form>
        </div>

        <section class="card card-flush">
            <div class="card-header">
                <h2 class="section-title">Son Atanan Görevler</h2>
                <a href="gorevler.php?gorunum=benim" class="btn btn-ghost btn-sm">Tüm Görevler &rarr;</a>
            </div>
            <?php if (empty($aktif_gorevler)): ?>
                <div class="empty-state">
                    <?php echo bos_durum_gorseli('gorev'); ?>
                    <div class="empty-state-title">Üzerinizde açık görev yok</div>
                    <p>Yeni bir görev atandığında burada ilerleme çubuğuyla birlikte görünecektir.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table dashboard-task-table">
                        <thead>
                            <tr>
                                <th class="col-task-main">Görev</th>
                                <th>Birim</th>
                                <th>İlerleme</th>
                                <th>Son Tarih</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($aktif_gorevler as $gorev): ?>
                                <?php
                                $birim_bilgi = birim_etiketi(isset($gorev['birim']) ? $gorev['birim'] : 'genel');
                                $durum_sinifi = 'status-' . $gorev['durum'];
                                $g_ilerleme = (int) $gorev['ilerleme'];
                                ?>
                                <tr>
                                    <td data-label="Görev" class="col-task-main">
                                        <div class="task-cell-stack">
                                            <div class="task-cell-meta">
                                                <span class="code-chip"><?php echo e($gorev['proje_kodu']); ?></span>
                                                <?php if ((int) $gorev['alt_toplam'] > 0): ?>
                                                    <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>#haftalik-plan" class="task-cell-plan-link">
                                                        Plan (<?php echo (int) $gorev['alt_tamamlanan']; ?>/<?php echo (int) $gorev['alt_toplam']; ?>) &rarr;
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>" class="task-title-link task-title-block">
                                                <?php echo e($gorev['baslik']); ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td data-label="Birim">
                                        <span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo birim_ikonu(isset($gorev['birim']) ? $gorev['birim'] : 'genel'); ?><span><?php echo e($birim_bilgi['metin']); ?></span></span>
                                    </td>
                                    <td data-label="İlerleme">
                                        <div class="inline-progress">
                                            <div class="inline-progress-bar">
                                                <div class="inline-progress-fill<?php echo $g_ilerleme >= 100 ? ' is-complete' : ''; ?>" style="width: <?php echo $g_ilerleme; ?>%;"></div>
                                            </div>
                                            <span class="inline-progress-val">%<?php echo $g_ilerleme; ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Son Tarih"><?php echo son_tarih_aciliyet_html($gorev['son_tarih'], $gorev['durum'] === 'tamamlandi'); ?></td>
                                    <td data-label="Durum">
                                        <select class="status-select <?php echo e($durum_sinifi); ?>"
                                                aria-label="Görev durumunu değiştir"
                                                data-task-status
                                                data-task-id="<?php echo (int) $gorev['id']; ?>"
                                                data-csrf="<?php echo e($csrf); ?>">
                                            <option value="yapilacak"<?php echo $gorev['durum'] === 'yapilacak' ? ' selected' : ''; ?>>Yapılacak</option>
                                            <option value="devam_ediyor"<?php echo $gorev['durum'] === 'devam_ediyor' ? ' selected' : ''; ?>>Devam Ediyor</option>
                                            <option value="tamamlandi"<?php echo $gorev['durum'] === 'tamamlandi' ? ' selected' : ''; ?>>Tamamlandı</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="dashboard-aside">
        <section class="card">
            <div class="section-header" style="margin-bottom:0.5rem;">
                <h2 class="section-title">Genel İlerleme</h2>
                <span class="mono text-muted"><?php echo (int) $genel_durum_sayilari['tamamlandi']; ?>/<?php echo (int) $genel_toplam_gorev; ?></span>
            </div>
            <div class="donut-wrap" role="img" aria-label="Genel tamamlanma oranı %<?php echo (int) $genel_ilerleme_yuzde; ?>">
                <svg class="donut-svg" viewBox="0 0 140 140">
                    <circle class="donut-track" cx="70" cy="70" r="<?php echo $donut_yaricap; ?>"></circle>
                    <circle class="donut-fill"
                            cx="70"
                            cy="70"
                            r="<?php echo $donut_yaricap; ?>"
                            stroke-dasharray="<?php echo number_format($donut_cevre, 2, '.', ''); ?>"
                            stroke-dashoffset="<?php echo number_format($donut_offset, 2, '.', ''); ?>"></circle>
                </svg>
                <div class="donut-center">
                    <span class="donut-percent">%<?php echo (int) $genel_ilerleme_yuzde; ?></span>
                    <span class="donut-caption">Tamamlandı</span>
                </div>
            </div>
            <div class="donut-legend">
                <div class="donut-stat donut-stat-done">
                    <div class="donut-stat-val"><?php echo (int) $genel_durum_sayilari['tamamlandi']; ?></div>
                    <div class="donut-stat-lbl">Biten</div>
                </div>
                <div class="donut-stat donut-stat-active">
                    <div class="donut-stat-val"><?php echo (int) $genel_durum_sayilari['devam_ediyor']; ?></div>
                    <div class="donut-stat-lbl">Aktif</div>
                </div>
                <div class="donut-stat donut-stat-todo">
                    <div class="donut-stat-val"><?php echo (int) $genel_durum_sayilari['yapilacak']; ?></div>
                    <div class="donut-stat-lbl">Bekleyen</div>
                </div>
            </div>
            <?php if ($toplam_alt_gorev > 0): ?>
                <div style="margin-top:0.75rem;padding-top:0.65rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.4rem;font-size:0.8rem;">
                    <span class="text-muted">Haftalık İlerleme</span>
                    <span class="mono" style="font-weight:600;color:var(--accent);"><?php echo (int) $toplam_alt_tamamlanan; ?> / <?php echo (int) $toplam_alt_gorev; ?> Haftalık Adım</span>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-header">
                <h2 class="section-title">Yaklaşan Teslimler</h2>
                <a href="takvim.php" class="btn btn-ghost btn-sm">Takvim</a>
            </div>
            <?php if (empty($yaklasan_gorevler)): ?>
                <div class="empty-state" style="padding:1.5rem 1rem;">
                    <?php echo bos_durum_gorseli('takvim'); ?>
                    <p>Üzerinize kayıtlı yaklaşan ana veya haftalık görev bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="deadline-list">
                    <?php foreach ($yaklasan_gorevler as $yg): ?>
                        <?php
                        $haftalik_mi = ($yg['tur'] === 'haftalik');
                        $kendi_tarihi_var = !empty($yg['kendi_tarihi_var']);
                        $tarih_degeri = isset($yg['son_tarih']) ? trim((string) $yg['son_tarih']) : '';
                        $ts = ($kendi_tarihi_var && $tarih_degeri !== '') ? strtotime($tarih_degeri) : false;
                        $gun_no = $ts ? date('d', $ts) : '--';
                        $ay_no = $ts ? (int) date('n', $ts) : 0;
                        $ay_kisa = isset($kisa_aylar[$ay_no]) ? $kisa_aylar[$ay_no] : '';
                        $td = isset($yg['teslim_durumu']) ? $yg['teslim_durumu'] : array('durum' => 'normal', 'kisa' => '');
                        $rozet_sinif = 'deadline-ok';
                        if ($td['durum'] === 'gecikti') {
                            $rozet_sinif = 'deadline-overdue';
                        } elseif ($td['durum'] === 'bugun' || $td['durum'] === 'yarin' || $td['durum'] === 'yaklasiyor') {
                            $rozet_sinif = 'deadline-soon';
                        }
                        ?>
                        <a href="<?php echo e($yg['hedef_link']); ?>" class="deadline-item<?php echo $haftalik_mi ? ' is-weekly-item' : ''; ?>">
                            <div class="deadline-date-box<?php echo $haftalik_mi ? ' is-weekly' : ''; ?>">
                                <?php if ($kendi_tarihi_var && $ts): ?>
                                    <span class="deadline-day"><?php echo e($gun_no); ?></span>
                                    <span class="deadline-month"><?php echo e($ay_kisa); ?></span>
                                <?php else: ?>
                                    <span class="deadline-day"><?php echo e($yg['kisa_etiket'] !== '' ? $yg['kisa_etiket'] : 'H1'); ?></span>
                                    <span class="deadline-month">PLAN</span>
                                <?php endif; ?>
                            </div>
                            <div class="deadline-info">
                                <div class="deadline-title-row">
                                    <span class="deadline-kind-badge <?php echo $haftalik_mi ? 'is-weekly' : 'is-main'; ?>"><?php echo e($yg['etiket']); ?></span>
                                    <span class="deadline-title" title="<?php echo e($yg['baslik']); ?>"><?php echo e($yg['baslik']); ?></span>
                                </div>
                                <div class="deadline-room" title="<?php echo e($yg['alt_bilgi']); ?>"><?php echo e($yg['alt_bilgi']); ?></div>
                            </div>
                            <?php if ($kendi_tarihi_var && $tarih_degeri !== ''): ?>
                                <?php $aciliyet = gorev_aciliyet_bilgisi($tarih_degeri, false); ?>
                                <span class="deadline-badge <?php echo e($rozet_sinif . ' ' . $aciliyet['sinif']); ?>" title="<?php echo e($aciliyet['etiket']); ?>" style="display:inline-flex;align-items:center;gap:0.28rem;flex-shrink:0;">
                                    <?php if (!empty($aciliyet['pulse'])): ?>
                                        <span class="urgency-pulse-dot" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <?php echo stickman_durum_ikonu($aciliyet['mod']); ?>
                                    <?php if (!empty($td['kisa'])): ?>
                                        <span><?php echo e($td['kisa']); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="deadline-badge deadline-ok urgency-active" title="Haftalık plan adımı" style="display:inline-flex;align-items:center;gap:0.28rem;flex-shrink:0;">
                                    <?php echo stickman_durum_ikonu('walk'); ?>
                                    <span>Haftalık</span>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($ekip_lideri): ?>
            <section class="card">
                <div class="section-header" style="margin-bottom:0.65rem;">
                    <h2 class="section-title">Veritabanı Yedeği</h2>
                    <span class="badge badge-yonetici">Ekip Lideri</span>
                </div>
                <p class="form-hint" style="margin-top:0;margin-bottom:0.85rem;">
                    <?php if (yonetici_mi($kullanici)): ?>
                        Tüm çalışma odaları, görevler ve alt görevleri tek tıkla <code>.sql</code> olarak indirin.
                    <?php else: ?>
                        Kurduğunuz çalışma odalarına ait görev, üye, yorum ve alt görevleri tek tıkla <code>.sql</code> olarak indirin.
                    <?php endif; ?>
                </p>
                <form method="post" action="yedek_indir.php">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <button type="submit" class="btn btn-secondary btn-block btn-sm">Veritabanı Yedeği İndir (.sql)</button>
                </form>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
