<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$sayfa_basligi = 'Hakkında';
$aktif_sayfa = 'hakkimizda';

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">GörevTakip Nasıl Çalışır?</h1>
        <p class="page-subtitle">Yazılım ekiplerinin projelerini ve görev dağıtımını sade biçimde yönetmesi için tasarlandı.</p>
    </div>
    <div class="page-actions">
        <?php if (giris_yapili_mi()): ?>
            <a href="panel.php" class="btn btn-primary">Çalışma Paneline Git</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary">Giriş Yap</a>
        <?php endif; ?>
    </div>
</div>

<div class="stack-lg">
    <section>
        <div class="section-header">
            <h2 class="section-title">3 Adımda İş Akışı</h2>
        </div>
        <div class="grid-3">
            <article class="card">
                <span class="step-num">1</span>
                <h3 class="feature-title">Proje Oluşturun</h3>
                <p>Proje adını, kısa kodunu ve hedef tarih aralığını tanımlayarak çalışma alanınızı açın.</p>
            </article>

            <article class="card">
                <span class="step-num">2</span>
                <h3 class="feature-title">Görevleri Dağıtın</h3>
                <p>Ekip üyelerini projeye ekleyin; görevleri öncelik ve teslim tarihleriyle sorumlulara atayın.</p>
            </article>

            <article class="card">
                <span class="step-num">3</span>
                <h3 class="feature-title">İlerlemeyi İzleyin</h3>
                <p>Yapılacak, Devam Ediyor ve Tamamlandı aşamaları ile ekip yorumlarını anlık olarak takip edin.</p>
            </article>
        </div>
    </section>

    <section>
        <div class="section-header">
            <h2 class="section-title">Roller ve Yetkiler</h2>
        </div>
        <div class="grid-2">
            <article class="card">
                <div class="section-header">
                    <h3 class="section-title">Ekip Yöneticisi</h3>
                    <span class="badge badge-yonetici">Yönetici</span>
                </div>
                <p>Proje planlamasını yapar, ekibi organize eder ve görev dağıtımını yönetir.</p>
                <ul class="clean-list">
                    <li>Yeni proje oluşturma, düzenleme ve arşivleme</li>
                    <li>Projelere ekip üyesi ekleme ve çıkarma</li>
                    <li>Görev oluşturma, öncelik ve sorumlu atama</li>
                    <li>Tüm görevlerin ilerleyişini ve yorumları takip etme</li>
                </ul>
            </article>

            <article class="card">
                <div class="section-header">
                    <h3 class="section-title">Ekip Üyesi</h3>
                    <span class="badge badge-uye">Üye</span>
                </div>
                <p>Dahil olduğu projeleri görüntüler ve kendisine atanan görevleri tamamlar.</p>
                <ul class="clean-list">
                    <li>Çalışma panelinde kendi görevlerini tek bakışta görme</li>
                    <li>Görev durumunu anlık olarak güncelleme</li>
                    <li>Görev detayında ekip notları ve yorumlar paylaşma</li>
                    <li>Profil bilgilerini ve şifresini yönetme</li>
                </ul>
            </article>
        </div>
    </section>

    <section class="card">
        <h2 class="section-title" style="margin-bottom: 0.65rem;">Güvenli ve Sade Altyapı</h2>
        <p>
            GörevTakip, oturum tabanlı yetkilendirme ile ziyaretçi ve ekip alanlarını birbirinden ayırır.
            Tüm veritabanı işlemleri parametreli PDO sorguları ile yürütülür; form gönderimleri CSRF koruması,
            parolalar ise tek yönlü özetleme ile korunur.
        </p>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
