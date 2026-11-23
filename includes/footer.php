    </div>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <div>
            <strong>GörevTakip</strong> — Yazılım Proje ve Görev Dağıtım Sistemi
        </div>
        <div class="footer-links">
            <a href="index.php">Ana Sayfa</a>
            <a href="hakkimizda.php">Hakkında</a>
            <?php if (giris_yapili_mi()): ?>
                <a href="panel.php">Panel</a>
            <?php else: ?>
                <a href="login.php">Giriş Yap</a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
