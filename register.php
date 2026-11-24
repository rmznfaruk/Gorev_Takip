<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (giris_yapili_mi()) {
    yonlendir('panel.php');
}

$hatalar = array();
$ad_soyad = '';
$eposta = '';
$unvan = '';
$oda_kodu = isset($_GET['oda_kodu']) ? strtoupper(trim((string) $_GET['oda_kodu'])) : '';
$rol = 'uye';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $ad_soyad = isset($_POST['ad_soyad']) ? trim((string) $_POST['ad_soyad']) : '';
    $eposta = isset($_POST['eposta']) ? trim((string) $_POST['eposta']) : '';
    $unvan = isset($_POST['unvan']) ? trim((string) $_POST['unvan']) : '';
    $oda_kodu = isset($_POST['oda_kodu']) ? strtoupper(trim((string) $_POST['oda_kodu'])) : '';
    // Guvenlik: POST ile gelen herhangi bir rol degeri yok sayilir, her zaman 'uye' sabitlenir.
    $rol = 'uye';
    $sifre = isset($_POST['sifre']) ? (string) $_POST['sifre'] : '';
    $sifre_tekrar = isset($_POST['sifre_tekrar']) ? (string) $_POST['sifre_tekrar'] : '';

    if (!csrf_dogrula($token)) {
        $hatalar[] = 'Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.';
    }
    if ($ad_soyad === '' || strlen($ad_soyad) < 3) {
        $hatalar[] = 'Ad soyad alanı en az 3 karakter olmalıdır.';
    }
    if ($eposta === '' || !filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
        $hatalar[] = 'Lütfen geçerli bir e-posta adresi girin.';
    }
    if (strlen($sifre) < 6) {
        $hatalar[] = 'Şifre en az 6 karakter uzunluğunda olmalıdır.';
    }
    if ($sifre !== $sifre_tekrar) {
        $hatalar[] = 'Girilen şifreler birbiriyle eşleşmiyor.';
    }

    $hedef_oda = null;
    if ($oda_kodu !== '' && empty($hatalar)) {
        $oda_sorgu = $pdo->prepare('SELECT id, ad FROM projeler WHERE UPPER(davet_kodu) = ? LIMIT 1');
        $oda_sorgu->execute(array($oda_kodu));
        $hedef_oda = $oda_sorgu->fetch();
        if (!$hedef_oda) {
            $hatalar[] = 'Girilen grup / oda kodu geçersiz. Kodu kontrol edin veya boş bırakarak kayıt olun.';
        }
    }

    if (empty($hatalar)) {
        $kontrol = $pdo->prepare('SELECT id FROM kullanicilar WHERE eposta = ? LIMIT 1');
        $kontrol->execute(array($eposta));
        if ($kontrol->fetch()) {
            $hatalar[] = 'Bu e-posta adresi ile kayıtlı bir hesap zaten bulunuyor.';
        }
    }

    if (empty($hatalar)) {
        $sifre_hash = password_hash($sifre, PASSWORD_DEFAULT);
        $varsayilan_unvan = ($unvan !== '') ? $unvan : 'Yazılım Geliştirici';

        $simdi = istanbul_simdi();
        $ekle = $pdo->prepare(
            'INSERT INTO kullanicilar (ad_soyad, eposta, sifre_hash, rol, unvan, olusturma_tarihi) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ekle->execute(array($ad_soyad, $eposta, $sifre_hash, 'uye', $varsayilan_unvan, $simdi));
        $yeni_kullanici_id = (int) $pdo->lastInsertId();

        if ($hedef_oda) {
            $uye_ekle = $pdo->prepare('INSERT INTO proje_uyeleri (proje_id, kullanici_id, proje_rolu, yetki, katilim_tarihi) VALUES (?, ?, ?, ?, ?)');
            $uye_ekle->execute(array((int) $hedef_oda['id'], $yeni_kullanici_id, 'Ekip Üyesi', 'uye', $simdi));

            aktivite_kaydet(
                $pdo,
                (int) $hedef_oda['id'],
                $yeni_kullanici_id,
                $ad_soyad . ' davet koduyla odaya katıldı.'
            );

            session_regenerate_id(true);
            $_SESSION['kullanici_id'] = $yeni_kullanici_id;
            $_SESSION['kullanici_rol'] = 'uye';
            $_SESSION['kullanici_ad'] = $ad_soyad;
            giris_denemesi_sifirla();

            flash_ekle('success', 'Hesabınız oluşturuldu ve "' . $hedef_oda['ad'] . '" grubuna katıldınız.');
            yonlendir('proje_detay.php?id=' . (int) $hedef_oda['id']);
        }

        flash_ekle('success', 'Hesabınız oluşturuldu. Şimdi e-posta ve şifrenizle giriş yapabilirsiniz.');
        yonlendir('login.php');
    }
}

$sayfa_basligi = 'Kayıt Ol';
$aktif_sayfa = 'register';

require __DIR__ . '/includes/header.php';
?>

<div class="auth-shell auth-shell-wide">
    <div class="card">
        <div class="auth-header">
            <div class="auth-brand" aria-hidden="true">
                <span class="brand-logo">
                    <svg width="34" height="34" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="34" height="34" rx="9" fill="#2563EB"/>
                        <path d="M17 12.2V21.2" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round"/>
                        <path d="M17 16.5H10.5C9.4 16.5 8.5 17.4 8.5 18.5V21.2" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M17 16.5H23.5C24.6 16.5 25.5 17.4 25.5 18.5V21.2" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="17" cy="9.2" r="3.2" fill="#FFFFFF"/>
                        <circle cx="8.5" cy="24.2" r="2.7" fill="#FFFFFF"/>
                        <circle cx="17" cy="24.2" r="2.9" fill="#34D399"/>
                        <circle cx="25.5" cy="24.2" r="2.7" fill="#FFFFFF"/>
                    </svg>
                </span>
            </div>
            <h1 class="auth-title">Hesap Oluştur</h1>
            <p class="text-muted">Ekibinizle görev dağıtımı ve takibi için yeni hesap açın.</p>
        </div>

        <?php if (!empty($hatalar)): ?>
            <div class="alert alert-error" role="alert">
                <div>
                    <?php foreach ($hatalar as $hata): ?>
                        <div><?php echo e($hata); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php" data-validate-form novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="ad_soyad">Ad Soyad</label>
                    <input
                        type="text"
                        id="ad_soyad"
                        name="ad_soyad"
                        class="form-control"
                        value="<?php echo e($ad_soyad); ?>"
                        placeholder="Örn: Deniz Yılmaz"
                        autocomplete="name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="eposta">E-posta Adresi</label>
                    <input
                        type="email"
                        id="eposta"
                        name="eposta"
                        class="form-control"
                        value="<?php echo e($eposta); ?>"
                        placeholder="ad@ornek.com"
                        autocomplete="email"
                        required
                    >
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="unvan">Unvan / Uzmanlık</label>
                    <input
                        type="text"
                        id="unvan"
                        name="unvan"
                        class="form-control"
                        value="<?php echo e($unvan); ?>"
                        placeholder="Örn: Backend Geliştirici"
                    >
                </div>

                <div class="form-group">
                    <label for="oda_kodu">Grup / Oda Kodu (İsteğe bağlı)</label>
                    <input
                        type="text"
                        id="oda_kodu"
                        name="oda_kodu"
                        class="form-control mono"
                        value="<?php echo e($oda_kodu); ?>"
                        placeholder="Örn: ODA-7K4M9P"
                        autocomplete="off"
                    >
                    <span class="form-hint">Bir grubun davet kodunuz varsa girin, kayıt sonrası doğrudan odaya katılırsınız.</span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="sifre">Şifre</label>
                    <input
                        type="password"
                        id="sifre"
                        name="sifre"
                        class="form-control"
                        placeholder="En az 6 karakter"
                        autocomplete="new-password"
                        data-min-length="6"
                        data-password-source
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="sifre_tekrar">Şifre Tekrar</label>
                    <input
                        type="password"
                        id="sifre_tekrar"
                        name="sifre_tekrar"
                        class="form-control"
                        placeholder="Şifreyi tekrar girin"
                        autocomplete="new-password"
                        data-password-confirm
                        required
                    >
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 0.5rem;">Kayıt Ol</button>
            <p class="form-note" style="text-align: center; margin-top: 0.75rem;">Yeni hesaplar ekip üyesi olarak oluşturulur.</p>
        </form>
    </div>

    <p class="auth-footer">
        Zaten hesabınız var mı? <a href="login.php">Giriş Yap</a>
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
