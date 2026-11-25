<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$sayfa_basligi = 'Ana Sayfa';
$aktif_sayfa = 'index';
$oturum_var = giris_yapili_mi();

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div>
        <span class="hero-eyebrow">Görev Dağıtım ve Takip Platformu</span>
        <h1 class="hero-title">Görevleri ekibinize net biçimde dağıtın, ilerlemeyi tek ekrandan izleyin.</h1>
        <p class="hero-lead">
            Projelerinizi düzenli tutun, her göreve sorumlu ve öncelik atayın.
            Ekip üyeleri kendi işlerini anlık olarak güncelleyip yorumlarla süreci paylaşsın.
        </p>
        <div class="hero-actions">
            <?php if ($oturum_var): ?>
                <a href="panel.php" class="btn btn-primary">Çalışma Paneline Git</a>
                <a href="projeler.php" class="btn btn-secondary">Projeler</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">Giriş Yap</a>
                <a href="register.php" class="btn btn-secondary">Hesap Oluştur</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="preview-window" aria-label="Örnek görev dağıtım panosu">
        <div class="preview-topbar">
            <div class="preview-project">
                <span class="code-chip">GTK-01</span>
                <span>Web Portalı Sprinti</span>
            </div>
            <span class="status-pill status-tamamlandi">
                <span class="status-dot" aria-hidden="true"></span>
                <span>Aktif</span>
            </span>
        </div>
        <div class="preview-columns">
            <div class="preview-col">
                <div class="preview-col-head">
                    <span class="preview-col-label">
                        <span class="col-dot col-dot-todo"></span>
                        <span>Yapılacak</span>
                    </span>
                    <span class="preview-col-count">2</span>
                </div>
                <div class="preview-card">
                    <div class="preview-card-title">API yetkilendirme testi</div>
                    <div class="preview-card-foot">
                        <span class="priority-indicator priority-yuksek">
                            <span class="priority-dot" aria-hidden="true"></span>
                            <span>Yüksek</span>
                        </span>
                        <?php echo kullanici_avatar_html('Elif Yılmaz', 'avatar-xs'); ?>
                    </div>
                </div>
                <div class="preview-card">
                    <div class="preview-card-title">Profil şifre güncelleme</div>
                    <div class="preview-card-foot">
                        <span class="priority-indicator priority-orta">
                            <span class="priority-dot" aria-hidden="true"></span>
                            <span>Orta</span>
                        </span>
                        <?php echo kullanici_avatar_html('Mert Demir', 'avatar-xs'); ?>
                    </div>
                </div>
            </div>

            <div class="preview-col">
                <div class="preview-col-head">
                    <span class="preview-col-label">
                        <span class="col-dot col-dot-progress"></span>
                        <span>Devam Ediyor</span>
                    </span>
                    <span class="preview-col-count">1</span>
                </div>
                <div class="preview-card">
                    <div class="preview-card-title">Görev durum API uç noktası</div>
                    <div class="preview-card-foot">
                        <span class="priority-indicator priority-yuksek">
                            <span class="priority-dot" aria-hidden="true"></span>
                            <span>Yüksek</span>
                        </span>
                        <?php echo kullanici_avatar_html('Zeynep Kaya', 'avatar-xs'); ?>
                    </div>
                </div>
            </div>

            <div class="preview-col">
                <div class="preview-col-head">
                    <span class="preview-col-label">
                        <span class="col-dot col-dot-done"></span>
                        <span>Tamamlandı</span>
                    </span>
                    <span class="preview-col-count">1</span>
                </div>
                <div class="preview-card">
                    <div class="preview-card-title">Veritabanı şeması</div>
                    <div class="preview-card-foot">
                        <span class="priority-indicator priority-dusuk">
                            <span class="priority-dot" aria-hidden="true"></span>
                            <span>Tamam</span>
                        </span>
                        <?php echo kullanici_avatar_html('Deniz Kaya', 'avatar-xs'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="landing-section">
    <div class="grid-3">
        <article class="feature-card">
            <div class="feature-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            <h2 class="feature-title">Düzenli Projeler</h2>
            <p>Her proje için ekip üyelerini belirleyin, kapsam ve teslim tarihlerini tek yerden yönetin.</p>
        </article>

        <article class="feature-card">
            <div class="feature-icon feature-icon-warning" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="5" r="3"></circle>
                    <line x1="12" y1="8" x2="12" y2="16"></line>
                    <line x1="12" y1="12" x2="6" y2="16"></line>
                    <line x1="12" y1="12" x2="18" y2="16"></line>
                    <circle cx="6" cy="18" r="2"></circle>
                    <circle cx="12" cy="18" r="2"></circle>
                    <circle cx="18" cy="18" r="2"></circle>
                </svg>
            </div>
            <h2 class="feature-title">Hızlı Görev Dağıtımı</h2>
            <p>Görevleri öncelik ve son tarih ile ekip üyelerine atayın, iş yükünü dengede tutun.</p>
        </article>

        <article class="feature-card">
            <div class="feature-icon feature-icon-success" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
            </div>
            <h2 class="feature-title">Anlık Durum Takibi</h2>
            <p>Görev durumlarını sayfa yenilemeden güncelleyin, yorumlarla ekip içi iletişimi canlı tutun.</p>
        </article>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
