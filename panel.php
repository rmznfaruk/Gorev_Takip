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

// Yaklasan ve geciken teslim tarihleri (oda izolasyonlu, tamamlanmamis ve son tarihi olan gorevler)
$stmt = $pdo->prepare('
    SELECT g.id, g.baslik, g.durum, g.oncelik, g.birim, g.son_tarih,
           p.id AS proje_id, p.ad AS proje_adi, p.kod AS proje_kodu,
           u.ad_soyad AS atanan_adi
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u ON u.id = g.atanan_id
    WHERE g.durum <> ?
      AND g.son_tarih IS NOT NULL
      AND (
          p.yonetici_id = ?
          OR EXISTS (SELECT 1 FROM proje_uyeleri pu WHERE pu.proje_id = p.id AND pu.kullanici_id = ?)
      )
    ORDER BY g.son_tarih ASC, CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END
    LIMIT 6
');
$stmt->execute(array('tamamlandi', $kullanici_id, $kullanici_id));
$yaklasan_gorevler = $stmt->fetchAll();
foreach ($yaklasan_gorevler as $idx => $yg) {
    $yaklasan_gorevler[$idx]['teslim_durumu'] = teslim_durumu_hesapla($yg['son_tarih'], $yg['durum']);
}

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
        <h1 class="page-title">Merhaba, <?php echo e($kullanici['ad_soyad']); ?></h1>
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
                        ?>
                        <a href="proje_detay.php?id=<?php echo (int) $proje['id']; ?>" class="room-mini-card">
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
                    <div class="empty-state-title">Üzerinizde açık görev yok</div>
                    <p>Yeni bir görev atandığında burada ilerleme çubuğuyla birlikte görünecektir.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Görev</th>
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
                                    <td>
                                        <a href="gorev_detay.php?id=<?php echo (int) $gorev['id']; ?>" class="task-title-link">
                                            <span class="task-id-muted"><?php echo e($gorev['proje_kodu']); ?></span>
                                            <span><?php echo e($gorev['baslik']); ?></span>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="unit-tag <?php echo e($birim_bilgi['sinif']); ?>"><?php echo e($birim_bilgi['metin']); ?></span>
                                    </td>
                                    <td>
                                        <div class="inline-progress">
                                            <div class="inline-progress-bar">
                                                <div class="inline-progress-fill<?php echo $g_ilerleme >= 100 ? ' is-complete' : ''; ?>" style="width: <?php echo $g_ilerleme; ?>%;"></div>
                                            </div>
                                            <span class="inline-progress-val">%<?php echo $g_ilerleme; ?></span>
                                        </div>
                                    </td>
                                    <td class="date-muted"><?php echo e(tarih_format($gorev['son_tarih'])); ?></td>
                                    <td>
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
                <div style="margin-top:0.75rem;padding-top:0.65rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:0.8rem;">
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
                    <p>Yaklaşan veya geciken teslim tarihi bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="deadline-list">
                    <?php foreach ($yaklasan_gorevler as $yg): ?>
                        <?php
                        $ts = strtotime((string) $yg['son_tarih']);
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
                        <a href="gorev_detay.php?id=<?php echo (int) $yg['id']; ?>" class="deadline-item">
                            <div class="deadline-date-box">
                                <span class="deadline-day"><?php echo e($gun_no); ?></span>
                                <span class="deadline-month"><?php echo e($ay_kisa); ?></span>
                            </div>
                            <div class="deadline-info">
                                <div class="deadline-title"><?php echo e($yg['baslik']); ?></div>
                                <div class="deadline-room"><?php echo e($yg['proje_adi']); ?></div>
                            </div>
                            <?php if (!empty($td['kisa'])): ?>
                                <span class="deadline-badge <?php echo $rozet_sinif; ?>"><?php echo e($td['kisa']); ?></span>
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
