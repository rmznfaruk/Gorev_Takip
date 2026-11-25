<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];

$ay = isset($_GET['ay']) ? (int) $_GET['ay'] : (int) date('n');
$yil = isset($_GET['yil']) ? (int) $_GET['yil'] : (int) date('Y');
if ($ay < 1 || $ay > 12) {
    $ay = (int) date('n');
}
if ($yil < 2020 || $yil > 2035) {
    $yil = (int) date('Y');
}

$filtre_proje = isset($_GET['proje_id']) ? (int) $_GET['proje_id'] : 0;
if ($filtre_proje > 0 && !proje_erisim_var_mi($pdo, $filtre_proje, $kullanici)) {
    $filtre_proje = 0;
}

$ay_baslangic = sprintf('%04d-%02d-01', $yil, $ay);
$ay_ts = strtotime($ay_baslangic);
$ay_gun_sayisi = (int) date('t', $ay_ts);
$ay_bitis = sprintf('%04d-%02d-%02d', $yil, $ay, $ay_gun_sayisi);
$ilk_gun_hafta = (int) date('N', $ay_ts); // 1 (Pzt) .. 7 (Paz)

$onceki_ay = $ay - 1;
$onceki_yil = $yil;
if ($onceki_ay < 1) {
    $onceki_ay = 12;
    $onceki_yil--;
}

$sonraki_ay = $ay + 1;
$sonraki_yil = $yil;
if ($sonraki_ay > 12) {
    $sonraki_ay = 1;
    $sonraki_yil++;
}

$ay_adlari = array(
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
);
$ay_adi = isset($ay_adlari[$ay]) ? $ay_adlari[$ay] : (string) $ay;

$proje_listesi = kullanici_odalari_getir($pdo, $kullanici);

// Oda izolasyonu: Yalnizca kullanicinin eristigi odalardaki ana gorevler
$sql = '
    SELECT g.id, g.proje_id, g.baslik, g.durum, g.oncelik, g.birim, g.son_tarih,
           p.ad AS proje_adi, p.kod AS proje_kodu,
           u.ad_soyad AS atanan_adi
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u ON u.id = g.atanan_id
    WHERE g.son_tarih IS NOT NULL
      AND g.son_tarih <> \'\'
      AND g.son_tarih >= ?
      AND g.son_tarih <= ?
      AND (
          p.yonetici_id = ?
          OR EXISTS (
              SELECT 1 FROM proje_uyeleri pu
              WHERE pu.proje_id = p.id AND pu.kullanici_id = ?
          )
      )
';
$params = array($ay_baslangic, $ay_bitis, $kullanici_id, $kullanici_id);

if ($filtre_proje > 0) {
    $sql .= ' AND g.proje_id = ?';
    $params[] = $filtre_proje;
}

$sql .= ' ORDER BY g.son_tarih ASC, CASE g.oncelik WHEN \'yuksek\' THEN 1 WHEN \'orta\' THEN 2 ELSE 3 END, g.id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$aylik_gorevler = $stmt->fetchAll();

// Oda izolasyonu: Yalnizca kullanicinin eristigi odalardaki haftalik alt gorevler (son_tarih dolu olanlar)
$alt_sql = '
    SELECT ag.id AS alt_id, ag.gorev_id, ag.hafta_etiketi, ag.baslik, ag.son_tarih, ag.tamamlandi, ag.sira,
           g.proje_id, g.baslik AS ust_gorev_baslik, g.durum AS ust_gorev_durum, g.oncelik, g.birim,
           p.ad AS proje_adi, p.kod AS proje_kodu,
           u.ad_soyad AS atanan_adi
    FROM alt_gorevler ag
    INNER JOIN gorevler g ON g.id = ag.gorev_id
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u ON u.id = COALESCE(ag.atanan_id, g.atanan_id)
    WHERE ag.son_tarih IS NOT NULL
      AND ag.son_tarih <> \'\'
      AND ag.son_tarih >= ?
      AND ag.son_tarih <= ?
      AND (
          p.yonetici_id = ?
          OR EXISTS (
              SELECT 1 FROM proje_uyeleri pu
              WHERE pu.proje_id = p.id AND pu.kullanici_id = ?
          )
      )
';
$alt_params = array($ay_baslangic, $ay_bitis, $kullanici_id, $kullanici_id);

if ($filtre_proje > 0) {
    $alt_sql .= ' AND g.proje_id = ?';
    $alt_params[] = $filtre_proje;
}

$alt_sql .= ' ORDER BY ag.son_tarih ASC, ag.sira ASC, ag.id ASC';

$alt_stmt = $pdo->prepare($alt_sql);
$alt_stmt->execute($alt_params);
$aylik_alt_gorevler = $alt_stmt->fetchAll();

$gunluk_gorevler = array();
foreach ($aylik_gorevler as $idx => $gorev) {
    $tarih_anahtar = substr((string) $gorev['son_tarih'], 0, 10);
    $tamam_mi = ($gorev['durum'] === 'tamamlandi');
    $aciliyet = gorev_aciliyet_bilgisi($gorev['son_tarih'], $tamam_mi);
    $oge = array(
        'tur' => 'ana_gorev',
        'hedef_gorev_id' => (int) $gorev['id'],
        'proje_id' => (int) $gorev['proje_id'],
        'proje_kodu' => $gorev['proje_kodu'],
        'proje_adi' => $gorev['proje_adi'],
        'baslik' => $gorev['baslik'],
        'hafta_etiketi' => '',
        'kisa_hafta' => '',
        'ust_gorev_baslik' => '',
        'son_tarih' => $gorev['son_tarih'],
        'tamamlandi_mi' => $tamam_mi,
        'aciliyet' => $aciliyet,
    );
    if (!isset($gunluk_gorevler[$tarih_anahtar])) {
        $gunluk_gorevler[$tarih_anahtar] = array();
    }
    $gunluk_gorevler[$tarih_anahtar][] = $oge;
}

foreach ($aylik_alt_gorevler as $ag) {
    $tarih_anahtar = substr((string) $ag['son_tarih'], 0, 10);
    $tamam_mi = ((int) $ag['tamamlandi'] === 1);
    $aciliyet = gorev_aciliyet_bilgisi($ag['son_tarih'], $tamam_mi);
    $oge = array(
        'tur' => 'alt_gorev',
        'hedef_gorev_id' => (int) $ag['gorev_id'],
        'proje_id' => (int) $ag['proje_id'],
        'proje_kodu' => $ag['proje_kodu'],
        'proje_adi' => $ag['proje_adi'],
        'baslik' => $ag['baslik'],
        'hafta_etiketi' => (string) $ag['hafta_etiketi'],
        'kisa_hafta' => kisa_hafta_etiketi($ag['hafta_etiketi']),
        'ust_gorev_baslik' => (string) $ag['ust_gorev_baslik'],
        'son_tarih' => $ag['son_tarih'],
        'tamamlandi_mi' => $tamam_mi,
        'aciliyet' => $aciliyet,
    );
    if (!isset($gunluk_gorevler[$tarih_anahtar])) {
        $gunluk_gorevler[$tarih_anahtar] = array();
    }
    $gunluk_gorevler[$tarih_anahtar][] = $oge;
}

$toplam_takvim_ogesi = count($aylik_gorevler) + count($aylik_alt_gorevler);
$bugun_str = date('Y-m-d');
$proje_param = $filtre_proje > 0 ? '&amp;proje_id=' . $filtre_proje : '';

$sayfa_basligi = 'Teslim Takvimi — ' . $ay_adi . ' ' . $yil;
$aktif_sayfa = 'takvim';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Teslim Takvimi</h1>
        <p class="page-subtitle"><?php echo e($ay_adi . ' ' . $yil); ?> &middot; Odalarınızdaki ana görev ve haftalık alt görev teslim tarihleri.</p>
    </div>
    <div class="page-actions">
        <a href="takvim.php?ay=<?php echo $onceki_ay; ?>&amp;yil=<?php echo $onceki_yil; ?><?php echo $proje_param; ?>" class="btn btn-secondary btn-sm">&larr; Önceki Ay</a>
        <a href="takvim.php?ay=<?php echo (int) date('n'); ?>&amp;yil=<?php echo (int) date('Y'); ?><?php echo $proje_param; ?>" class="btn btn-ghost btn-sm">Bugün</a>
        <a href="takvim.php?ay=<?php echo $sonraki_ay; ?>&amp;yil=<?php echo $sonraki_yil; ?><?php echo $proje_param; ?>" class="btn btn-secondary btn-sm">Sonraki Ay &rarr;</a>
    </div>
</div>

<div class="calendar-toolbar">
    <div class="calendar-nav">
        <a href="takvim.php?ay=<?php echo $onceki_ay; ?>&amp;yil=<?php echo $onceki_yil; ?><?php echo $proje_param; ?>" class="btn btn-secondary btn-sm">&larr; Önceki</a>
        <span class="calendar-month-title"><?php echo e($ay_adi . ' ' . $yil); ?></span>
        <a href="takvim.php?ay=<?php echo $sonraki_ay; ?>&amp;yil=<?php echo $sonraki_yil; ?><?php echo $proje_param; ?>" class="btn btn-secondary btn-sm">Sonraki &rarr;</a>
        <a href="takvim.php?ay=<?php echo (int) date('n'); ?>&amp;yil=<?php echo (int) date('Y'); ?><?php echo $proje_param; ?>" class="btn btn-ghost btn-sm">Bugün</a>
    </div>

    <form method="get" action="takvim.php" class="calendar-filter-form">
        <input type="hidden" name="ay" value="<?php echo $ay; ?>">
        <input type="hidden" name="yil" value="<?php echo $yil; ?>">
        <select name="proje_id" class="form-control form-control-sm calendar-filter-select" aria-label="Oda filtresi" onchange="this.form.submit()">
            <option value="0">Tüm Odalarım (<?php echo (int) $toplam_takvim_ogesi; ?> teslim)</option>
            <?php foreach ($proje_listesi as $oda): ?>
                <option value="<?php echo (int) $oda['id']; ?>"<?php echo $filtre_proje === (int) $oda['id'] ? ' selected' : ''; ?>>
                    <?php echo e($oda['kod'] . ' — ' . $oda['ad']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filtre_proje > 0): ?>
            <a href="takvim.php?ay=<?php echo $ay; ?>&amp;yil=<?php echo $yil; ?>" class="btn btn-ghost btn-sm">Temizle</a>
        <?php endif; ?>
    </form>
</div>

<div class="calendar-legend" aria-label="Aciliyet ve durum göstergeleri">
    <span class="calendar-legend-label">Aciliyet:</span>
    <span class="calendar-legend-item urgency-calm">
        <?php echo stickman_durum_ikonu('chill'); ?>
        <span>Rahat</span>
    </span>
    <span class="calendar-legend-item urgency-active">
        <?php echo stickman_durum_ikonu('walk'); ?>
        <span>Planlı</span>
    </span>
    <span class="calendar-legend-item urgency-soon">
        <?php echo stickman_durum_ikonu('rush'); ?>
        <span>Az Kaldı</span>
    </span>
    <span class="calendar-legend-item urgency-overdue">
        <span class="urgency-pulse-dot" aria-hidden="true"></span>
        <?php echo stickman_durum_ikonu('panic'); ?>
        <span>Bugün/Gecikti</span>
    </span>
    <span class="calendar-legend-item urgency-done" style="text-decoration:none;">
        <?php echo stickman_durum_ikonu('done'); ?>
        <span>Tamamlandı</span>
    </span>
</div>

<div class="calendar-grid">
    <?php
    $hafta_gunleri = array('Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz');
    foreach ($hafta_gunleri as $gun_adi):
    ?>
        <div class="calendar-weekday"><?php echo e($gun_adi); ?></div>
    <?php endforeach; ?>

    <?php for ($bos = 1; $bos < $ilk_gun_hafta; $bos++): ?>
        <div class="calendar-cell is-outside"></div>
    <?php endfor; ?>

    <?php for ($gun = 1; $gun <= $ay_gun_sayisi; $gun++): ?>
        <?php
        $tarih_str = sprintf('%04d-%02d-%02d', $yil, $ay, $gun);
        $bugun_mu = ($tarih_str === $bugun_str);
        $gun_gorevleri = isset($gunluk_gorevler[$tarih_str]) ? $gunluk_gorevler[$tarih_str] : array();
        ?>
        <div class="calendar-cell<?php echo $bugun_mu ? ' is-today' : ''; ?>">
            <div class="calendar-day-head">
                <span style="display:inline-flex;align-items:center;gap:0.35rem;min-width:0;">
                    <span class="calendar-day-num"><?php echo $gun; ?></span>
                    <?php if ($bugun_mu): ?>
                        <span class="today-pill">Bugün</span>
                    <?php endif; ?>
                </span>
                <?php if (!empty($gun_gorevleri)): ?>
                    <span class="mono text-muted" style="font-size:0.7rem;"><?php echo count($gun_gorevleri); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($gun_gorevleri)): ?>
                <div class="calendar-events">
                    <?php foreach ($gun_gorevleri as $oge): ?>
                        <?php
                        $aciliyet = $oge['aciliyet'];
                        if ($oge['tur'] === 'alt_gorev') {
                            $chip_baslik_bilgi = $oge['proje_kodu'] . ' — [' . $oge['hafta_etiketi'] . '] ' . $oge['baslik'] . ' (' . $oge['ust_gorev_baslik'] . ') · ' . $aciliyet['etiket'];
                        } else {
                            $chip_baslik_bilgi = $oge['proje_kodu'] . ' — ' . $oge['baslik'] . ' · ' . $aciliyet['etiket'];
                        }
                        ?>
                        <a href="gorev_detay.php?id=<?php echo (int) $oge['hedef_gorev_id']; ?>"
                           class="calendar-chip <?php echo e($aciliyet['sinif']); ?>"
                           title="<?php echo e($chip_baslik_bilgi); ?>">
                            <?php if (!empty($aciliyet['pulse'])): ?>
                                <span class="urgency-pulse-dot" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php echo stickman_durum_ikonu($aciliyet['mod']); ?>
                            <?php if ($oge['tur'] === 'alt_gorev' && $oge['kisa_hafta'] !== ''): ?>
                                <span class="calendar-week-badge"><?php echo e($oge['kisa_hafta']); ?></span>
                            <?php endif; ?>
                            <span class="calendar-chip-title"><?php echo e($oge['baslik']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endfor; ?>

    <?php
    $toplam_hucre = ($ilk_gun_hafta - 1) + $ay_gun_sayisi;
    $kalan_bos = ($toplam_hucre % 7 === 0) ? 0 : (7 - ($toplam_hucre % 7));
    for ($b = 0; $b < $kalan_bos; $b++):
    ?>
        <div class="calendar-cell is-outside"></div>
    <?php endfor; ?>
</div>

<?php if ($toplam_takvim_ogesi === 0): ?>
    <div class="card empty-state" style="margin-top:1rem;">
        <?php echo bos_durum_gorseli('takvim'); ?>
        <div class="empty-state-title"><?php echo e($ay_adi . ' ' . $yil); ?> için planlanmış teslim yok</div>
        <p>Son teslim tarihi bu aya denk gelen ana görevler ve haftalık alt görevler takvim hücrelerinde aciliyet durumuna göre listelenir.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
