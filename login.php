<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (giris_yapili_mi()) {
    yonlendir('panel.php');
}

$hata = '';
$eposta = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (isset($_POST['eposta'])) {
        $eposta = trim((string) $_POST['eposta']);
    } elseif (isset($_POST['giris_bilgisi'])) {
        $eposta = trim((string) $_POST['giris_bilgisi']);
    }
    $sifre = isset($_POST['sifre']) ? (string) $_POST['sifre'] : '';

    if (!csrf_dogrula($token)) {
        $hata = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
    } elseif (giris_denemesi_kilitli_mi()) {
        $kalan = giris_kilit_kalan_dakika();
        $hata = 'Çok fazla hatalı giriş denemesi yaptınız. Hesap güvenliği için lütfen ' . ($kalan > 0 ? $kalan : 1) . ' dakika sonra tekrar deneyin.';
    } elseif ($eposta === '' || $sifre === '') {
        $hata = 'Lütfen kullanıcı adınızı (veya e-posta adresinizi) ve şifrenizi girin.';
    } else {
        // Isim (ad_soyad) veya e-posta ile buyuk/kucuk harf duyarsiz giris
        $arama = function_exists('mb_strtolower') ? mb_strtolower($eposta, 'UTF-8') : strtolower($eposta);
        $stmt = $pdo->prepare('
            SELECT id, ad_soyad, eposta, sifre_hash, rol
            FROM kullanicilar
            WHERE LOWER(eposta) = LOWER(?) OR LOWER(ad_soyad) = LOWER(?)
            LIMIT 1
        ');
        $stmt->execute(array($arama, $arama));
        $kayit = $stmt->fetch();

        if ($kayit && password_verify($sifre, $kayit['sifre_hash'])) {
            giris_denemesi_sifirla();
            session_regenerate_id(true);
            $_SESSION['kullanici_id'] = (int) $kayit['id'];
            $_SESSION['kullanici_rol'] = $kayit['rol'];
            $_SESSION['kullanici_ad'] = $kayit['ad_soyad'];
            unset($_SESSION['csrf_token']);

            flash_ekle('success', 'Hoş geldiniz, ' . $kayit['ad_soyad'] . '.');
            yonlendir('panel.php');
        } else {
            giris_basarisiz_kaydet();
            $hata = 'Kullanıcı adı / e-posta veya şifre hatalı.';
        }
    }
}

$sayfa_basligi = 'Giriş Yap';
$aktif_sayfa = 'login';

require __DIR__ . '/includes/header.php';
?>

<div class="auth-shell">
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
            <h1 class="auth-title">Giriş Yap</h1>
            <p class="text-muted">Çalışma alanınıza ulaşmak için hesap bilgilerinizi girin.</p>
        </div>

        <?php if ($hata !== ''): ?>
            <div class="alert alert-error" role="alert">
                <span><?php echo e($hata); ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" data-validate-form novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

            <div class="form-group">
                <label for="eposta">Kullanıcı Adı veya E-posta</label>
                <input
                    type="text"
                    id="eposta"
                    name="eposta"
                    class="form-control"
                    value="<?php echo e($eposta); ?>"
                    placeholder="Ad Soyad veya e-posta adresiniz"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="sifre">Şifre</label>
                <input
                    type="password"
                    id="sifre"
                    name="sifre"
                    class="form-control"
                    placeholder="Şifrenizi girin"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block">Giriş Yap</button>
        </form>
    </div>

    <p class="auth-footer">
        Hesabınız yok mu? <a href="register.php">Kayıt Ol</a>
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
