# GörevTakip — Proje ve Görev Dağılım Sistemi (v3)

GörevTakip, yazılım ve TEKNOFEST proje ekiplerinin çalışma odalarını, ana görevlerini, **Hafta 1 / Hafta 2 haftalık alt görevlerini (checklist)**, ekip üyelerini, dosya/bağlantı eklerini, bildirimlerini ve teslim takvimini tek panelden yönetmesi için geliştirilmiş **PHP (7.3.10+ / 8.x)** ve **MySQL / SQLite** tabanlı bir web uygulamasıdır.

## Özellikler

- **Temiz Canlı Kurulum & Esnek Giriş**:
  - Örnek/demo veri ve hazır hesap olmadan 9 temiz boş tablo ile başlar; herkes `Kayıt Ol` (`register.php`) üzerinden kendi hesabını oluşturur.
  - Kullanıcılar `login.php` üzerinden **kullanıcı adı (`ad_soyad`, büyük/küçük harf duyarsız)** veya **e-posta adresi** ile giriş yapabilir.
- **Haftalık Alt Görevlendirme (Hafta 1 / Hafta 2 Checklist)**:
  - Her ana görevin içine girilerek `Hafta 1`, `Hafta 2`, `Hafta 3`... şeklinde gruplu alt görevler tanımlanabilir ve oda üyelerine atanabilir.
  - Alt görevler AJAX (`api/alt_gorev.php`) üzerinden sayfa yenilenmeden eklenir, tamamlandı olarak işaretlenir veya silinir; görevin ilerleme yüzdesi (`%`) canlı olarak güncellenir.
- **Çalışma Odaları, Davet Kodu ve Otomatik Ekip Liderliği**:
  - Her kayıtlı kullanıcı yeni çalışma odası oluşturabilir; odayı oluşturan kullanıcı otomatik olarak o odanın **Ekip Lideri** olur ve sistem kriptografik olarak benzersiz bir **Oda Davet Kodu** (`ODA-XXXXXX`) üretir.
  - **Sıkı Oda İzolasyonu (Sıfır IDOR)**: Kullanıcılar yalnızca yöneticisi veya üyesi oldukları odaların görevlerine, alt görevlerine, yorumlarına, eklerine, aktivitelerine ve takvim kayıtlarına erişebilir.
  - **Oda İçi Yetki Hiyerarşisi**:
    - **Ekip Lideri (Oda Kurucusu)**: Odayı kurduğu anda otomatik olarak tam yetki alır; yıllık/ana görev atar, haftalık alt görev ekler, duyuru düzenler, davet kodunu yeniler, üye çıkarır, odayı siler ve üyeleri **Birim Lideri (`lider`)** / **Üye (`uye`)** yapabilir.
    - **Birim Lideri (`lider`)**: Ekip Lideri tarafından atanan alt-yöneticidir; o oda içinde yeni görev oluşturabilir, haftalık alt görev atayabilir ve oda duyurusunu güncelleyebilir.
    - **Üye (`uye`)**: Davet koduyla katılan üyedir; kendisine atanan ana görev ve alt görevlerin durumunu günceller, dosya/bağlantı ve yorum ekler.
- **Oda Sabit Duyurusu & Aktivite Akışı**:
  - Oda liderleri/yöneticileri çalışma odasının üstünde sabit duyuru yayınlayabilir.
  - Görev oluşturma, alt görev tamamlama, durum değişikliği, yorum, dosya yükleme ve üye katılımları odanın **Aktivite Akışı**nda kronolojik olarak kaydedilir.
- **Uygulama İçi Bildirim Çanı (`api/bildirimler.php`)**:
  - Görev ataması, haftalık alt görev ataması, yorum, dosya yükleme, yetki değişikliği ve durum güncellemelerinde ilgili kişilere anlık bildirim düşer (kendine bildirim gitmez).
- **Aylık Teslim Takvimi (`takvim.php`) & Genel Bakış Paneli (`panel.php`)**:
  - Aylık takvimde odalara göre filtrelenebilir görev son tarihleri görüntülenir.
  - Panelde genel ilerleme grafiği, yaklaşan/geciken teslim uyarıları, aktif odalar ve takım yükü özeti yer alır.
- **Güvenli PDF / Görsel Yükleme & Oda Bazlı Tek Tıkla SQL Yedeği (`yedek_indir.php`)**:
  - Maksimum **8 MB** PDF, PNG, JPG, JPEG yüklemeleri sunucu tarafında uzantı + **magic-byte** doğrulamasından geçer.
  - En az bir çalışma odası kurmuş olan **Ekip Liderleri**, `exec()` / `mysqldump` gerektirmeden (InfinityFree uyumlu) yalnızca kendi kurdukları odalara ait verilerin UTF-8 `.sql` yedeğini tek tıkla indirebilir.
- **Güvenlik Sertleştirmesi**:
  - PDO prepared statements, `password_hash` / `password_verify`, tüm form ve AJAX isteklerinde CSRF token doğrulaması, `htmlspecialchars` (XSS koruması), `HttpOnly` + `SameSite=Lax` oturum çerezleri, güvenlik HTTP başlıkları, giriş hız sınırlaması (10 dakikada 5 hatalı deneme) ve `.htaccess` klasör koruması.

## Veritabanı Şeması (`database.sql`)

Sistem yabancı anahtar (`FOREIGN KEY`) ilişkileriyle bağlı **9 tablodan** oluşur:

1. `kullanicilar` — Kullanıcı hesapları, şifre özetleri, sistem rolleri (`yonetici`, `uye`) ve unvanlar
2. `projeler` — Çalışma odaları, oda davet kodu (`davet_kodu`), sabit duyuru (`duyuru`, `duyuru_tarihi`), kurucu (`yonetici_id`) ve tarih aralığı
3. `proje_uyeleri` — Oda-kullanıcı ilişkisi, proje rolü ve oda içi yetki (`lider` / `uye`)
4. `gorevler` — Odaya bağlı ana görevler, birim etiketi (`birim`), atanan/oluşturan, durum, öncelik ve son tarih
5. `alt_gorevler` — Ana göreve bağlı haftalık alt görevler (`hafta_etiketi`, `baslik`, `atanan_id`, `son_tarih`, `tamamlandi`, `sira`)
6. `yorumlar` — Görev içi yorum geçmişi
7. `gorev_dosyalari` — Görevlere eklenen PDF/görsel dosyaları (`dosya`) ve harici bağlantılar (`link`)
8. `bildirimler` — Uygulama içi bildirim çanı kayıtları (`tur`, `mesaj`, `link`, `okundu`)
9. `aktiviteler` — Oda bazlı kronolojik aktivite akışı

## Hesap Oluşturma ve Oda Bazlı Roller

Kurulum sonrasında veritabanı tamamen boş (0 kullanıcı, 0 oda, 0 görev) başlar:

1. Her kullanıcı `Kayıt Ol` (`register.php`) sayfasından kendi hesabını oluşturur.
2. **Yeni Topluluk Odası** oluşturan kullanıcı otomatik olarak o odanın **Ekip Lideri** olur; görev atama, haftalık alt görev planlama, duyuru düzenleme, üye yönetimi, **Birim Lideri** atama ve kendi kurduğu odaların `.sql` yedeğini indirme yetkilerini kazanır.
3. Diğer ekip üyeleri paylaşılan **Oda Davet Kodu** (`ODA-XXXXXX`) ile odaya katılır ve Ekip Lideri tarafından gerektiğinde **Birim Lideri** olarak yetkilendirilebilir.

## Canlı Sunucu Kurulumu (InfinityFree / Paylaşımlı Hosting)

1. InfinityFree kontrol panelinden yeni bir MySQL veritabanı oluşturun.
2. **phpMyAdmin** içinde oluşturduğunuz veritabanını seçin ve `database.sql` dosyasını içe aktarın (InfinityFree'de veritabanı zaten seçili olduğu için `CREATE DATABASE` / `USE` satırlarını kaldırıp doğrudan `SET FOREIGN_KEY_CHECKS` satırından itibaren çalıştırabilirsiniz).
3. Tüm proje dosyalarını FTP veya Dosya Yöneticisi ile `htdocs/` dizinine yükleyin. `uploads/` klasörünün yazma iznine (`755`) ve `.htaccess` dosyalarının (`uploads/`, `config/`, `includes/`, `storage/`) eksiksiz yüklendiğine emin olun.
4. `config/db.php` içindeki `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` varsayılan değerlerini InfinityFree MySQL bilgilerinizle güncelleyin.
5. Uygulamayı açıp `Kayıt Ol` ile hesabınızı oluşturun ve ilk çalışma odanızı kurarak **Ekip Lideri** olarak ekibinizi davet edin.

## Yerel Kurulum (MySQL / XAMPP / AppServ)

1. Proje klasörünü web sunucunuzun kök dizinine kopyalayın.
2. `database.sql` dosyasını phpMyAdmin üzerinden içe aktarın.
3. Gerekirse `config/db.php` bağlantı bilgilerini (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) ortam değişkeni veya dosya içinden ayarlayın.

### Yerel Hızlı Test (SQLite ile)

MySQL kurmadan PHP dahili sunucusuyla çalıştırmak için:

```powershell
$env:APP_DB_DRIVER = "sqlite"
php -S 127.0.0.1:8080
```

İlk istekte `storage/test.sqlite` otomatik olarak aynı 9 temiz boş tablo ile oluşturulur. `Kayıt Ol` sayfasından hesap açarak hemen kullanmaya başlayabilirsiniz.

## Klasör Yapısı

```text
├── api/
│   ├── alt_gorev.php
│   ├── bildirimler.php
│   ├── gorev_durum.php
│   └── yorum_ekle.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── config/
│   ├── .htaccess
│   ├── app.php
│   └── db.php
├── includes/
│   ├── .htaccess
│   ├── auth.php
│   ├── footer.php
│   ├── header.php
│   └── helpers.php
├── storage/
│   └── .htaccess
├── uploads/
│   └── .htaccess
├── database.sql
├── dosya_indir.php
├── yedek_indir.php
├── takvim.php
├── index.php
├── hakkimizda.php
├── login.php
├── register.php
├── logout.php
├── panel.php
├── projeler.php
├── proje_detay.php
├── gorevler.php
├── gorev_detay.php
├── profil.php
├── .gitignore
└── README.md
```
