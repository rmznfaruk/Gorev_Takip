<?php
require_once __DIR__ . '/auth.php';

if (!isset($sayfa_basligi) || $sayfa_basligi === '') {
    $sayfa_basligi = 'GörevTakip';
}
if (!isset($aktif_sayfa)) {
    $aktif_sayfa = '';
}

$oturum_acik = giris_yapili_mi();
$ust_kullanici = null;
$ust_odalar = array();
$okunmamis_bildirim = 0;
$ust_ekip_lideri = false;

if ($oturum_acik) {
    if (isset($kullanici) && is_array($kullanici)) {
        $ust_kullanici = $kullanici;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $ust_kullanici = aktif_kullanici($pdo);
        $oturum_acik = ($ust_kullanici !== null);
    } else {
        $ust_kullanici = array(
            'id' => isset($_SESSION['kullanici_id']) ? (int) $_SESSION['kullanici_id'] : 0,
            'ad_soyad' => isset($_SESSION['kullanici_ad']) ? $_SESSION['kullanici_ad'] : 'Kullanıcı',
            'rol' => isset($_SESSION['kullanici_rol']) ? $_SESSION['kullanici_rol'] : 'uye',
        );
    }

    if ($oturum_acik && $ust_kullanici && isset($pdo) && $pdo instanceof PDO) {
        if (function_exists('kullanici_odalari_getir')) {
            try {
                $ust_odalar = kullanici_odalari_getir($pdo, $ust_kullanici);
            } catch (Exception $e) {
                $ust_odalar = array();
            }
        }
        try {
            $stmt_b = $pdo->prepare('SELECT COUNT(*) FROM bildirimler WHERE kullanici_id = ? AND okundu = 0');
            $stmt_b->execute(array((int) $ust_kullanici['id']));
            $okunmamis_bildirim = (int) $stmt_b->fetchColumn();
        } catch (Exception $e) {
            $okunmamis_bildirim = 0;
        }
        try {
            $ust_ekip_lideri = kullanici_ekip_lideri_mi($pdo, $ust_kullanici);
        } catch (Exception $e) {
            $ust_ekip_lideri = yonetici_mi($ust_kullanici);
        }
    } elseif ($oturum_acik && $ust_kullanici) {
        $ust_ekip_lideri = yonetici_mi($ust_kullanici);
    }
}

$ust_arama_degeri = ($aktif_sayfa === 'gorevler' && isset($_GET['q'])) ? trim((string) $_GET['q']) : '';
$hizli_yeni_gorev_link = !empty($ust_odalar)
    ? 'proje_detay.php?id=' . (int) $ust_odalar[0]['id'] . '&yeni=1'
    : 'projeler.php';

$flash_mesajlar = flash_al();
$csrf_meta = csrf_token();
$css_dosya_yolu = __DIR__ . '/../assets/css/style.css';
$js_dosya_yolu = __DIR__ . '/../assets/js/app.js';
$css_ver = is_file($css_dosya_yolu) ? (int) @filemtime($css_dosya_yolu) : 20261125;
$js_ver = is_file($js_dosya_yolu) ? (int) @filemtime($js_dosya_yolu) : 20261125;
$app_css_href = 'assets/css/style.css?v=' . $css_ver;
$app_js_src = 'assets/js/app.js?v=' . $js_ver;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e($csrf_meta); ?>">
    <title><?php echo e($sayfa_basligi); ?> — GörevTakip</title>
    <link rel="icon" href="favicon.ico" sizes="32x32">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <script>
    (function(){try{if(localStorage.getItem('gt_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}})();
    </script>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo e((string) $css_ver); ?>">
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-left">
            <button
                type="button"
                id="drawerToggle"
                class="menu-trigger"
                aria-expanded="false"
                aria-controls="sideDrawer"
                aria-label="Menüyü aç"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <line x1="4" y1="6.5" x2="20" y2="6.5"></line>
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <line x1="4" y1="17.5" x2="20" y2="17.5"></line>
                </svg>
            </button>

            <a href="<?php echo $oturum_acik ? 'panel.php' : 'index.php'; ?>" class="brand">
                <span class="brand-logo" aria-hidden="true">
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
                <span>GörevTakip</span>
            </a>
        </div>

        <?php if ($oturum_acik && $ust_kullanici): ?>
            <form action="gorevler.php" method="get" class="topbar-search" role="search">
                <span class="topbar-search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </span>
                <input
                    type="search"
                    name="q"
                    class="topbar-search-input"
                    value="<?php echo e($ust_arama_degeri); ?>"
                    placeholder="Görev, oda veya kişi ara..."
                    aria-label="Görev, oda veya kişi ara"
                >
            </form>
        <?php endif; ?>

        <div class="topbar-right">
            <button
                type="button"
                class="theme-toggle"
                data-theme-toggle
                aria-pressed="false"
                aria-label="Koyu moda geç"
                title="Koyu moda geç"
            >
                <svg class="theme-icon theme-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
                <svg class="theme-icon theme-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
            </button>

            <?php if ($oturum_acik && $ust_kullanici): ?>
                <div class="notif-wrap">
                    <button
                        type="button"
                        id="notifToggle"
                        class="notif-btn"
                        aria-expanded="false"
                        aria-controls="notifPanel"
                        aria-label="Bildirimler"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <span id="notifBadge" class="notif-badge"<?php echo $okunmamis_bildirim > 0 ? '' : ' hidden'; ?>>
                            <?php echo $okunmamis_bildirim > 99 ? '99+' : $okunmamis_bildirim; ?>
                        </span>
                    </button>

                    <div id="notifPanel" class="notif-panel" hidden>
                        <div class="notif-header">
                            <span class="notif-header-title">Bildirimler</span>
                            <button type="button" class="notif-read-all" data-notif-read-all>Tümünü okundu yap</button>
                        </div>
                        <div id="notifList" class="notif-list">
                            <div class="notif-empty">Bildirimler yükleniyor...</div>
                        </div>
                    </div>
                </div>

                <a href="<?php echo e($hizli_yeni_gorev_link); ?>" class="btn btn-primary btn-sm">
                    <span>+</span>
                    <span class="topbar-new-task-label">Yeni Görev</span>
                </a>

                <a href="profil.php" class="user-pill" title="<?php echo e($ust_kullanici['ad_soyad']); ?>">
                    <?php echo kullanici_avatar_html($ust_kullanici['ad_soyad'], 'avatar avatar-sm'); ?>
                    <span class="user-pill-name"><?php echo e($ust_kullanici['ad_soyad']); ?></span>
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary btn-sm">Giriş Yap</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div id="drawerBackdrop" class="drawer-backdrop" aria-hidden="true"></div>

<aside id="sideDrawer" class="side-drawer" aria-hidden="true" aria-label="Ana menü">
    <div class="drawer-header">
        <a href="<?php echo $oturum_acik ? 'panel.php' : 'index.php'; ?>" class="brand">
            <span class="brand-logo" aria-hidden="true">
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
            <span>GörevTakip</span>
        </a>

        <button type="button" id="drawerClose" class="drawer-close" aria-label="Menüyü kapat">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <line x1="6" y1="6" x2="18" y2="18"></line>
                <line x1="18" y1="6" x2="6" y2="18"></line>
            </svg>
        </button>
    </div>

    <div class="drawer-body">
        <div class="drawer-section-label">Menü</div>
        <nav aria-label="Sayfa geçişleri">
            <ul class="drawer-nav">
                <?php if ($oturum_acik && $ust_kullanici): ?>
                    <li>
                        <a href="panel.php" class="drawer-link<?php echo $aktif_sayfa === 'panel' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="7" height="9" rx="1.5"></rect>
                                <rect x="14" y="3" width="7" height="5" rx="1.5"></rect>
                                <rect x="14" y="12" width="7" height="9" rx="1.5"></rect>
                                <rect x="3" y="16" width="7" height="5" rx="1.5"></rect>
                            </svg>
                            <span>Genel Bakış</span>
                        </a>
                    </li>
                    <li>
                        <a href="projeler.php" class="drawer-link<?php echo $aktif_sayfa === 'projeler' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <span>Çalışma Odaları</span>
                        </a>
                    </li>
                    <li>
                        <a href="gorevler.php" class="drawer-link<?php echo $aktif_sayfa === 'gorevler' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 11l3 3L22 4"></path>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                            </svg>
                            <span>Görevler</span>
                        </a>
                    </li>
                    <li>
                        <a href="takvim.php" class="drawer-link<?php echo $aktif_sayfa === 'takvim' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <span>Takvim</span>
                        </a>
                    </li>
                    <li>
                        <a href="profil.php" class="drawer-link<?php echo $aktif_sayfa === 'profil' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>Profil &amp; Ayarlar</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="index.php" class="drawer-link<?php echo $aktif_sayfa === 'index' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5z"></path>
                            </svg>
                            <span>Ana Sayfa</span>
                        </a>
                    </li>
                    <li>
                        <a href="hakkimizda.php" class="drawer-link<?php echo $aktif_sayfa === 'hakkimizda' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <span>Hakkında</span>
                        </a>
                    </li>
                    <li>
                        <a href="login.php" class="drawer-link<?php echo $aktif_sayfa === 'login' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                <polyline points="10 17 15 12 10 7"></polyline>
                                <line x1="15" y1="12" x2="3" y2="12"></line>
                            </svg>
                            <span>Giriş Yap</span>
                        </a>
                    </li>
                    <li>
                        <a href="register.php" class="drawer-link<?php echo $aktif_sayfa === 'register' ? ' active' : ''; ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <line x1="20" y1="8" x2="20" y2="14"></line>
                                <line x1="23" y1="11" x2="17" y2="11"></line>
                            </svg>
                            <span>Kayıt Ol</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <?php if ($oturum_acik && $ust_kullanici): ?>
            <div class="drawer-divider"></div>
            <div class="drawer-section-label">Odalarım</div>
            <?php if (!empty($ust_odalar)): ?>
                <ul class="drawer-nav">
                    <?php foreach ($ust_odalar as $idx => $oda_item): ?>
                        <?php $renk_idx = (int) $oda_item['id'] % 6; ?>
                        <li>
                            <a href="proje_detay.php?id=<?php echo (int) $oda_item['id']; ?>" class="drawer-room-link">
                                <span class="room-dot room-dot-<?php echo $renk_idx; ?>" aria-hidden="true"></span>
                                <span class="drawer-room-name"><?php echo e($oda_item['ad']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="drawer-room-empty">Henüz bir odaya katılmadınız.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="drawer-footer">
        <?php if ($oturum_acik && $ust_kullanici): ?>
            <div class="drawer-user">
                <?php echo kullanici_avatar_html($ust_kullanici['ad_soyad'], 'avatar'); ?>
                <div class="drawer-user-info">
                    <span class="drawer-user-name"><?php echo e($ust_kullanici['ad_soyad']); ?></span>
                    <span class="drawer-user-role"><?php echo $ust_ekip_lideri ? 'Ekip Lideri' : 'Üye'; ?></span>
                </div>
            </div>
            <a href="logout.php" class="drawer-logout">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                <span>Çıkış Yap</span>
            </a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary btn-block">Giriş Yap</a>
            <a href="register.php" class="btn btn-secondary btn-block">Hesap Oluştur</a>
        <?php endif; ?>
    </div>
</aside>

<main class="main-content">
    <div class="container">
        <?php if (!empty($flash_mesajlar)): ?>
            <?php foreach ($flash_mesajlar as $flash): ?>
                <?php
                $flash_tur = isset($flash['tur']) ? $flash['tur'] : 'info';
                $alert_sinif = 'alert-info';
                if ($flash_tur === 'success') {
                    $alert_sinif = 'alert-success';
                } elseif ($flash_tur === 'error') {
                    $alert_sinif = 'alert-error';
                }
                ?>
                <div class="alert <?php echo $alert_sinif; ?>" role="alert">
                    <span><?php echo e($flash['mesaj']); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
