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

// Oda izolasyonu: Yalnizca kullanicinin eristigi odalardaki gorevler
$sql = '
    SELECT g.id, g.proje_id, g.baslik, g.durum, g.oncelik, g.birim, g.son_tarih,
           p.ad AS proje_adi, p.kod AS proje_kodu,
           u.ad_soyad AS atanan_adi
    FROM gorevler g
    INNER JOIN projeler p ON p.id = g.proje_id
    LEFT JOIN kullanicilar u ON u.id = g.atanan_id
    WHERE g.son_tarih IS NOT NULL
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

$gunluk_gorevler = array();
foreach ($aylik_gorevler as $idx => $gorev) {
    $tarih_anahtar = substr((string) $gorev['son_tarih'], 0, 10);
    $gorev['teslim_durumu'] = teslim_durumu_hesapla($gorev['son_tarih'], $gorev['durum']);
    $aylik_gorevler[$idx] = $gorev;
    if (!isset($gunluk_gorevler[$tarih_anahtar])) {
        $gunluk_gorevler[$tarih_anahtar] = array();
    }
    $gunluk_gorevler[$tarih_anahtar][] = $gorev;
}

$bugun_str = date('Y-m-d');
$proje_param = $filtre_proje > 0 ? '&amp;proje_id=' . $filtre_proje : '';

$sayfa_basligi = 'Teslim Takvimi — ' . $ay_adi . ' ' . $yil;
$aktif_sayfa = 'takvim';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Teslim Takvimi</h1>
        <p class="page-subtitle"><?php echo e($ay_adi . ' ' . $yil); ?> &middot; Odalarınızdaki görev son tarihleri.</p>
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
            <option value="0">Tüm Odalarım (<?php echo count($aylik_gorevler); ?> görev)</option>
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
                <span class="calendar-day-num"><?php echo $gun; ?></span>
                <?php if (!empty($gun_gorevleri)): ?>
                    <span class="mono text-muted" style="font-size:0.7rem;"><?php echo count($gun_gorevleri); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($gun_gorevleri)): ?>
                <div class="calendar-events">
                    <?php foreach ($gun_gorevleri as $g): ?>
                        <?php
                        $gecikti_mi = isset($g['teslim_durumu']['durum']) && $g['teslim_durumu']['durum'] === 'gecikti';
                        $chip_sinif = $gecikti_mi ? 'chip-overdue' : ('chip-' . $g['durum']);
                        ?>
                        <a href="gorev_detay.php?id=<?php echo (int) $g['id']; ?>"
                           class="calendar-chip <?php echo e($chip_sinif); ?>"
                           title="<?php echo e($g['proje_kodu'] . ' — ' . $g['baslik']); ?>">
                            <?php echo e($g['baslik']); ?>
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

<?php if (empty($aylik_gorevler)): ?>
    <div class="card empty-state" style="margin-top:1rem;">
        <?php echo bos_durum_gorseli('takvim'); ?>
        <div class="empty-state-title"><?php echo e($ay_adi . ' ' . $yil); ?> için planlanmış teslim yok</div>
        <p>Son teslim tarihi bu aya denk gelen görevler takvim hücrelerinde renkli rozet olarak listelenir.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
