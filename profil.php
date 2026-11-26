<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

giris_gerekli();
$kullanici = aktif_kullanici($pdo);
$kullanici_id = (int) $kullanici['id'];
$ekip_lideri = kullanici_ekip_lideri_mi($pdo, $kullanici);
$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_dogrula($token)) {
        flash_ekle('error', 'Güvenlik doğrulaması başarısız.');
        yonlendir('profil.php');
    }

    $islem = isset($_POST['islem']) ? $_POST['islem'] : '';

    if ($islem === 'profil_guncelle') {
        $ad_soyad = isset($_POST['ad_soyad']) ? trim((string) $_POST['ad_soyad']) : '';
        $unvan = isset($_POST['unvan']) ? trim((string) $_POST['unvan']) : '';

        if ($ad_soyad === '') {
            flash_ekle('error', 'Ad soyad alanı boş bırakılamaz.');
            yonlendir('profil.php');
        }
        if ($unvan === '') {
            $unvan = 'Ekip Üyesi';
        }

        $guncelle = $pdo->prepare('UPDATE kullanicilar SET ad_soyad = ?, unvan = ? WHERE id = ?');
        $guncelle->execute(array($ad_soyad, $unvan, $kullanici_id));
        $_SESSION['kullanici_ad'] = $ad_soyad;

        flash_ekle('success', 'Profil bilgileriniz güncellendi.');
        yonlendir('profil.php');
    }

    if ($islem === 'sifre_guncelle') {
        $mevcut = isset($_POST['mevcut_sifre']) ? (string) $_POST['mevcut_sifre'] : '';
        $yeni = isset($_POST['yeni_sifre']) ? (string) $_POST['yeni_sifre'] : '';
        $tekrar = isset($_POST['yeni_sifre_tekrar']) ? (string) $_POST['yeni_sifre_tekrar'] : '';

        if (strlen($yeni) < 6) {
            flash_ekle('error', 'Yeni şifre en az 6 karakter olmalıdır.');
            yonlendir('profil.php');
        }
        if ($yeni !== $tekrar) {
            flash_ekle('error', 'Yeni şifre ve tekrar alanı birbiriyle eşleşmiyor.');
            yonlendir('profil.php');
        }

        $stmt = $pdo->prepare('SELECT sifre_hash FROM kullanicilar WHERE id = ? LIMIT 1');
        $stmt->execute(array($kullanici_id));
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($mevcut, $hash)) {
            flash_ekle('error', 'Mevcut şifreniz doğrulanamadı.');
            yonlendir('profil.php');
        }

        $yeni_hash = password_hash($yeni, PASSWORD_DEFAULT);
        $guncelle = $pdo->prepare('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?');
        $guncelle->execute(array($yeni_hash, $kullanici_id));
        hatirla_tum_tokenlari_sil($pdo, $kullanici_id);
        hatirla_cerez_ayarla('', time() - 42000);

        flash_ekle('success', 'Şifreniz güncellendi.');
        yonlendir('profil.php');
    }
}

$sayfa_basligi = 'Profil Ayarları';
$aktif_sayfa = 'profil';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profil Ayarları</h1>
        <p class="page-subtitle">Hesap bilgilerinizi ve şifrenizi buradan yönetin.</p>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="section-header">
            <div style="display:flex;align-items:center;gap:0.65rem;min-width:0;">
                <?php echo kullanici_avatar_html($kullanici['ad_soyad'], 'avatar'); ?>
                <h2 class="section-title">Hesap Bilgileri</h2>
            </div>
            <span class="badge <?php echo $ekip_lideri ? 'badge-yonetici' : 'badge-uye'; ?>">
                <?php echo $ekip_lideri ? 'Ekip Lideri' : 'Üye'; ?>
            </span>
        </div>

        <form method="post" action="profil.php" data-validate-form>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="profil_guncelle">

            <div class="form-group">
                <label class="form-label" for="profil-eposta">E-posta Adresi</label>
                <input type="email" id="profil-eposta" class="form-control" value="<?php echo e($kullanici['eposta']); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label" for="profil-ad">Ad Soyad</label>
                <input type="text" id="profil-ad" name="ad_soyad" class="form-control" value="<?php echo e($kullanici['ad_soyad']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="profil-unvan">Unvan / Uzmanlık</label>
                <input type="text" id="profil-unvan" name="unvan" class="form-control" value="<?php echo e($kullanici['unvan']); ?>" placeholder="Örn: Yazılım Ekibi Üyesi, Aerodinamik Ekibi Üyesi" autocomplete="organization-title" required>
            </div>

            <div class="meta-list" style="margin-bottom:1rem;">
                <div class="meta-row">
                    <span class="meta-key">Kayıt Tarihi</span>
                    <span class="meta-val mono"><?php echo e(tarih_format($kullanici['olusturma_tarihi'], true)); ?></span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Bilgileri Güncelle</button>
        </form>
    </section>

    <section class="card">
        <div class="section-header">
            <h2 class="section-title">Şifre Değiştir</h2>
        </div>

        <form method="post" action="profil.php" data-validate-form>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="islem" value="sifre_guncelle">

            <div class="form-group">
                <label class="form-label" for="mevcut-sifre">Mevcut Şifre</label>
                <input type="password" id="mevcut-sifre" name="mevcut_sifre" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="yeni-sifre">Yeni Şifre</label>
                <input type="password" id="yeni-sifre" name="yeni_sifre" class="form-control" data-min-length="6" data-password-source required>
            </div>

            <div class="form-group">
                <label class="form-label" for="yeni-sifre-tekrar">Yeni Şifre (Tekrar)</label>
                <input type="password" id="yeni-sifre-tekrar" name="yeni_sifre_tekrar" class="form-control" data-password-confirm required>
            </div>

            <button type="submit" class="btn btn-secondary">Şifreyi Güncelle</button>
        </form>
    </section>
</div>

<?php if ($ekip_lideri): ?>
    <section class="card" style="margin-top: 1.35rem;">
        <div class="section-header">
            <h2 class="section-title">Ekip Lideri Araçları</h2>
            <span class="badge badge-yonetici">Ekip Lideri</span>
        </div>

        <div class="stack-sm">
            <div class="admin-tool-title">Veritabanı Yedeği</div>
            <p class="admin-tool-sub" style="margin-bottom: 0.25rem;">
                <?php if (yonetici_mi($kullanici)): ?>
                    Tüm tabloları (projeler, görevler, yorumlar ve ekler) UTF-8 SQL dosyası olarak indirir.
                <?php else: ?>
                    Yalnızca kurduğunuz çalışma odalarına ait projeler, üyeler, görevler, alt görevler, yorumlar, ekler ve aktiviteleri UTF-8 SQL dosyası olarak indirir.
                <?php endif; ?>
            </p>
            <div class="admin-tool-row">
                <div class="admin-tool-desc">
                    <div class="admin-tool-title"><?php echo yonetici_mi($kullanici) ? 'Tam Yedek (.sql)' : 'Oda Yedeği (.sql)'; ?></div>
                    <div class="admin-tool-sub">Tek tıkla güvenli PDO dışa aktarımı</div>
                </div>
                <form method="post" action="yedek_indir.php">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Veritabanı Yedeği İndir (.sql)</button>
                </form>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
