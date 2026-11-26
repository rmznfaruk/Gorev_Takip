<?php
/**
 * Genel yardimci fonksiyonlar.
 */

function e($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function flash_ekle($tur, $mesaj)
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = array();
    }
    $_SESSION['flash'][] = array(
        'tur' => $tur,
        'mesaj' => $mesaj,
    );
}

function flash_al()
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return array();
    }
    $mesajlar = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $mesajlar;
}

function durum_etiketi($durum)
{
    $harita = array(
        'yapilacak' => array('metin' => 'Yapılacak', 'sinif' => 'badge-yapilacak'),
        'devam_ediyor' => array('metin' => 'Devam Ediyor', 'sinif' => 'badge-devam'),
        'tamamlandi' => array('metin' => 'Tamamlandı', 'sinif' => 'badge-tamamlandi'),
    );
    if (isset($harita[$durum])) {
        return $harita[$durum];
    }
    return array('metin' => (string) $durum, 'sinif' => 'badge-yapilacak');
}

function proje_durum_etiketi($durum)
{
    $harita = array(
        'aktif' => array('metin' => 'Aktif', 'sinif' => 'badge-devam'),
        'tamamlandi' => array('metin' => 'Tamamlandı', 'sinif' => 'badge-tamamlandi'),
        'arsiv' => array('metin' => 'Arşiv', 'sinif' => 'badge-yapilacak'),
    );
    if (isset($harita[$durum])) {
        return $harita[$durum];
    }
    return array('metin' => (string) $durum, 'sinif' => 'badge-yapilacak');
}

function oncelik_etiketi($oncelik)
{
    $harita = array(
        'dusuk' => array('metin' => 'Düşük', 'sinif' => 'badge-dusuk'),
        'orta' => array('metin' => 'Orta', 'sinif' => 'badge-orta'),
        'yuksek' => array('metin' => 'Yüksek', 'sinif' => 'badge-yuksek'),
    );
    if (isset($harita[$oncelik])) {
        return $harita[$oncelik];
    }
    return array('metin' => (string) $oncelik, 'sinif' => 'badge-orta');
}

function istanbul_simdi($ofset_saniye = 0)
{
    try {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
        $simdi = new DateTimeImmutable('now', $tz);
        $ofset = (int) $ofset_saniye;
        if ($ofset !== 0) {
            $simdi = $simdi->modify(($ofset >= 0 ? '+' : '') . $ofset . ' seconds');
        }
        return $simdi->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return date('Y-m-d H:i:s', time() + (int) $ofset_saniye);
    }
}

function tarih_format($tarih, $saat_goster = false)
{
    if ($tarih === null || is_bool($tarih) || (!is_string($tarih) && !is_numeric($tarih))) {
        return '-';
    }

    $ham = trim((string) $tarih);
    if ($ham === '' || $ham === '0' || strpos($ham, '0000-00-00') === 0) {
        return '-';
    }

    $aylar = array(
        1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis',
        5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu',
        9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara',
    );

    try {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
        $dt = new DateTimeImmutable($ham, $tz);
        $dt = $dt->setTimezone($tz);
        $gun = $dt->format('d');
        $ay_no = (int) $dt->format('n');
        $ay = isset($aylar[$ay_no]) ? $aylar[$ay_no] : $dt->format('m');
        $yil = $dt->format('Y');
        if ($saat_goster) {
            return $gun . ' ' . $ay . ' ' . $yil . ' ' . $dt->format('H:i');
        }
        return $gun . ' ' . $ay . ' ' . $yil;
    } catch (Exception $e) {
        $zaman = strtotime($ham);
        if ($zaman === false) {
            return (string) $tarih;
        }
        $gun = date('d', $zaman);
        $ay = isset($aylar[(int) date('n', $zaman)]) ? $aylar[(int) date('n', $zaman)] : date('m', $zaman);
        $yil = date('Y', $zaman);

        if ($saat_goster) {
            return $gun . ' ' . $ay . ' ' . $yil . ' ' . date('H:i', $zaman);
        }
        return $gun . ' ' . $ay . ' ' . $yil;
    }
}

function bas_harfler($ad)
{
    $temiz = trim((string) $ad);
    if ($temiz === '') {
        return 'GT';
    }
    $parcalar = @preg_split('/\s+/u', $temiz);
    if (!is_array($parcalar)) {
        $parcalar = preg_split('/\s+/', $temiz);
    }
    if (!is_array($parcalar)) {
        $parcalar = array($temiz);
    }
    $harfler = '';
    $sayac = 0;
    foreach ($parcalar as $parca) {
        if ($parca === '') {
            continue;
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $harfler .= mb_strtoupper(mb_substr($parca, 0, 1, 'UTF-8'), 'UTF-8');
        } else {
            $harfler .= strtoupper(substr($parca, 0, 1));
        }
        $sayac++;
        if ($sayac >= 2) {
            break;
        }
    }
    return $harfler !== '' ? $harfler : 'GT';
}

function avatar_renk_sinifi($metin)
{
    $temiz = trim((string) $metin);
    if ($temiz === '') {
        return 'avatar-tone-1';
    }
    $kucuk = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    $hash = (int) sprintf('%u', crc32($kucuk));
    $ton = ($hash % 6) + 1;
    return 'avatar-tone-' . $ton;
}

function stickman_avatar_indeks($anahtar)
{
    $temiz = trim((string) $anahtar);
    if ($temiz === '') {
        return 0;
    }
    $kucuk = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    $hash = (int) sprintf('%u', crc32('stickman-pp-v1:' . $kucuk));
    return $hash % 10;
}

function stickman_avatar_svg($anahtar)
{
    $idx = stickman_avatar_indeks($anahtar);
    $govde = '<path d="M16 20v2.3M9 28.4c1.5-4 4.1-5.8 7-5.8s5.5 1.8 7 5.8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
    $kafa = '<circle cx="16" cy="13.8" r="6.1" fill="#FFFFFF" fill-opacity="0.88" stroke="currentColor" stroke-width="1.95"/>';

    $varyantlar = array(
        // 1. Kulaklikli yazilimci cop adam
        $govde
        . '<path d="M9.1 13.4a6.9 6.9 0 0 1 13.8 0" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/>'
        . $kafa
        . '<rect x="6.7" y="11.2" width="2.8" height="5.2" rx="1.3" fill="currentColor"/>'
        . '<rect x="22.5" y="11.2" width="2.8" height="5.2" rx="1.3" fill="currentColor"/>'
        . '<circle cx="13.8" cy="13.5" r="0.95" fill="currentColor"/>'
        . '<circle cx="18.2" cy="13.5" r="0.95" fill="currentColor"/>'
        . '<path d="M13.8 16.4q2.2 1.8 4.4 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>'
        . '<path d="M9.2 15.8q1.8 1.9 3.9 1.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>',

        // 2. Aviyonik / pilot gozluklu cop adam
        $govde
        . $kafa
        . '<path d="M11.8 7.9q4.2-1.8 8.4 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
        . '<path d="M10.7 12.1h4.5l-.5 2.8q-1.9.9-3.6-.5z" fill="currentColor" fill-opacity="0.24" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
        . '<path d="M16.8 12.1h4.5l-.4 2.3q-1.7 1.4-3.6.5z" fill="currentColor" fill-opacity="0.24" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
        . '<line x1="15.2" y1="12.5" x2="16.8" y2="12.5" stroke="currentColor" stroke-width="1.5"/>'
        . '<path d="M14.1 17.1q2.1 1.3 4.1-.2" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>',

        // 3. Yuvarlak gozluklu muhendis cop adam
        $govde
        . $kafa
        . '<path d="M13.2 7.5q2.8-2 5.8-.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>'
        . '<circle cx="13.5" cy="13.5" r="2.05" fill="#FFFFFF" stroke="currentColor" stroke-width="1.55"/>'
        . '<circle cx="18.5" cy="13.5" r="2.05" fill="#FFFFFF" stroke="currentColor" stroke-width="1.55"/>'
        . '<line x1="15.55" y1="13.5" x2="16.45" y2="13.5" stroke="currentColor" stroke-width="1.5"/>'
        . '<circle cx="13.5" cy="13.5" r="0.65" fill="currentColor"/>'
        . '<circle cx="18.5" cy="13.5" r="0.65" fill="currentColor"/>'
        . '<path d="M14.1 17q1.9 1.4 3.8 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',

        // 4. Kepli / sapkali cop adam
        $govde
        . $kafa
        . '<path d="M9.9 11.1c.4-4.1 3-5.8 6.1-5.8 3.1 0 5.6 1.7 6.1 5.8z" fill="currentColor" fill-opacity="0.22" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>'
        . '<path d="M9.4 11.1h15.6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
        . '<circle cx="16" cy="4.9" r="0.95" fill="currentColor"/>'
        . '<circle cx="13.9" cy="14" r="0.95" fill="currentColor"/>'
        . '<circle cx="18.1" cy="14" r="0.95" fill="currentColor"/>'
        . '<path d="M13.9 16.8q2.1 1.6 4.2 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>',

        // 5. Diken sacli enerjik cop adam
        $govde
        . $kafa
        . '<polyline points="11.1,8.9 10.4,5.3 13.6,7.4 16,3.8 18.4,7.4 21.6,5.3 20.9,8.9" fill="currentColor" fill-opacity="0.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="13.7" cy="13.5" r="1" fill="currentColor"/>'
        . '<circle cx="18.3" cy="13.5" r="1" fill="currentColor"/>'
        . '<path d="M13.4 16.3q2.6 2.5 5.2 0" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/>',

        // 6. Muhendis baretli (mekanik) cop adam
        $govde
        . $kafa
        . '<path d="M9.8 11c.3-4.2 2.9-6 6.2-6s5.9 1.8 6.2 6" fill="currentColor" fill-opacity="0.22" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '<line x1="16" y1="4.8" x2="16" y2="9.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '<line x1="8.3" y1="11" x2="23.7" y2="11" stroke="currentColor" stroke-width="2.05" stroke-linecap="round"/>'
        . '<circle cx="13.8" cy="14" r="0.95" fill="currentColor"/>'
        . '<circle cx="18.2" cy="14" r="0.95" fill="currentColor"/>'
        . '<path d="M14 16.9q2 1.4 4 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>',

        // 7. Kucuk yildizli / tacli lider cop adam
        $govde
        . $kafa
        . '<polygon points="11.5,8.3 12.2,4.3 16,6.7 19.8,4.3 20.5,8.3" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="1.65" stroke-linejoin="round"/>'
        . '<circle cx="16" cy="3.3" r="0.9" fill="currentColor"/>'
        . '<circle cx="13.8" cy="13.6" r="0.95" fill="currentColor"/>'
        . '<circle cx="18.2" cy="13.6" r="0.95" fill="currentColor"/>'
        . '<path d="M13.7 16.5q2.3 1.9 4.6 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',

        // 8. Kivircik / at kuyruklu cop adam
        $govde
        . '<path d="M21.7 10.6c2.9-1.2 4.3 1.7 2.6 4.6-.9 1.5-2.1.6-2.3-1.2" fill="currentColor" fill-opacity="0.22" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"/>'
        . $kafa
        . '<path d="M10.9 10.4q2.5-1.9 5.1-.5q2.6-1.4 5.1.5" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/>'
        . '<circle cx="13.8" cy="13.6" r="0.95" fill="currentColor"/>'
        . '<circle cx="18.2" cy="13.6" r="0.95" fill="currentColor"/>'
        . '<path d="M13.9 16.6q2.1 1.8 4.2 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>',

        // 9. Antenli / vizorlu elektronikci cop adam
        $govde
        . '<line x1="16" y1="7.6" x2="16" y2="4.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '<circle cx="16" cy="3.1" r="1.35" fill="currentColor"/>'
        . $kafa
        . '<rect x="10.8" y="11.7" width="10.4" height="3.2" rx="1.5" fill="currentColor" fill-opacity="0.24" stroke="currentColor" stroke-width="1.55"/>'
        . '<line x1="12.6" y1="13.3" x2="15" y2="13.3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>'
        . '<path d="M14.1 17.1q1.9 1.3 3.8 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>',

        // 10. Bandanali / gulumseyen cop adam
        $govde
        . '<path d="M21.9 11l3.6-1.6M21.9 11.5l3.8 1.6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>'
        . $kafa
        . '<path d="M10.1 10.3q5.9-.9 11.8 0M10.2 12.1q5.8-.8 11.6 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>'
        . '<path d="M12.5 14.3q1.1-1.2 2.2 0M17.3 14.3q1.1-1.2 2.2 0" stroke="currentColor" stroke-width="1.55" stroke-linecap="round"/>'
        . '<path d="M13.6 16.8q2.4 2.1 4.8 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
    );

    $icerik = isset($varyantlar[$idx]) ? $varyantlar[$idx] : $varyantlar[0];
    return '<svg class="stickman-pp" data-pp-variant="' . ($idx + 1) . '" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' . $icerik . '</svg>';
}

function kullanici_avatar_html($ad_soyad, $boyut_sinifi = 'avatar', $ek_sinif = '')
{
    $ad = trim((string) $ad_soyad);
    $etiket = $ad !== '' ? $ad : 'Kullanıcı';
    $boyut = trim((string) $boyut_sinifi);
    if ($boyut === '') {
        $boyut = 'avatar';
    } elseif ($boyut === 'avatar-sm') {
        $boyut = 'avatar avatar-sm';
    }
    $renk = avatar_renk_sinifi($etiket);
    $siniflar = trim($boyut . ' ' . $renk . ($ek_sinif !== '' ? ' ' . trim((string) $ek_sinif) : ''));
    return '<span class="' . e($siniflar) . '" title="' . e($etiket) . '">' . stickman_avatar_svg($etiket) . '</span>';
}

function oda_renk_sinifi($anahtar)
{
    $temiz = trim((string) $anahtar);
    if ($temiz === '') {
        return 'room-accent-1';
    }
    $kucuk = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    $hash = (int) sprintf('%u', crc32($kucuk));
    $ton = ($hash % 6) + 1;
    return 'room-accent-' . $ton;
}

function gorev_aciliyet_bilgisi($son_tarih, $tamamlandi_mi = false)
{
    $tamam = ($tamamlandi_mi === true || $tamamlandi_mi === 1 || $tamamlandi_mi === '1' || $tamamlandi_mi === 'tamamlandi');
    if ($tamam) {
        return array(
            'sinif' => 'urgency-done',
            'mod' => 'done',
            'pulse' => false,
            'etiket' => 'Tamamlandı',
            'gun' => null,
        );
    }

    $ham = ($son_tarih === null || is_bool($son_tarih)) ? '' : trim((string) $son_tarih);
    if ($ham === '' || $ham === '0' || strpos($ham, '0000-00-00') === 0) {
        return array(
            'sinif' => 'urgency-calm',
            'mod' => 'calm',
            'pulse' => false,
            'etiket' => 'Tarihsiz',
            'gun' => null,
        );
    }

    $tarih_str = substr($ham, 0, 10);
    $hedef_ts = strtotime($tarih_str . ' 00:00:00');
    $bugun_ts = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($hedef_ts === false || $bugun_ts === false) {
        return array(
            'sinif' => 'urgency-calm',
            'mod' => 'calm',
            'pulse' => false,
            'etiket' => 'Tarihsiz',
            'gun' => null,
        );
    }

    $gun = (int) round(($hedef_ts - $bugun_ts) / 86400);

    if ($gun <= 0) {
        $etiket = $gun < 0 ? (abs($gun) . ' gün gecikti') : 'Bugün son gün';
        return array(
            'sinif' => 'urgency-overdue',
            'mod' => 'panic',
            'pulse' => true,
            'etiket' => $etiket,
            'gun' => $gun,
        );
    }
    if ($gun >= 1 && $gun <= 3) {
        $etiket = $gun === 1 ? 'Yarın' : ($gun . ' gün kaldı');
        return array(
            'sinif' => 'urgency-soon',
            'mod' => 'rush',
            'pulse' => false,
            'etiket' => $etiket,
            'gun' => $gun,
        );
    }
    if ($gun >= 4 && $gun <= 7) {
        return array(
            'sinif' => 'urgency-active',
            'mod' => 'walk',
            'pulse' => false,
            'etiket' => $gun . ' gün kaldı',
            'gun' => $gun,
        );
    }

    return array(
        'sinif' => 'urgency-calm',
        'mod' => 'chill',
        'pulse' => false,
        'etiket' => $gun . ' gün kaldı',
        'gun' => $gun,
    );
}

function stickman_durum_ikonu($mod)
{
    $anahtar = is_string($mod) ? trim($mod) : 'chill';
    if ($anahtar === 'panic') {
        return '<svg class="stickman-status-icon stickman-panic" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1.2" x2="12" y2="2.8"></line><line x1="7.8" y1="2.2" x2="9" y2="3.5"></line><line x1="16.2" y1="2.2" x2="15" y2="3.5"></line><circle cx="12" cy="6.6" r="2.2"></circle><polyline points="12 10.6 7.2 8.4 9.5 5.8"></polyline><polyline points="12 10.6 16.8 8.4 14.5 5.8"></polyline><line x1="12" y1="8.9" x2="12" y2="15.2"></line><polyline points="12 15.2 8.5 18.2 9.2 21.5"></polyline><polyline points="12 15.2 15.5 18.2 14.8 21.5"></polyline></svg>';
    }
    if ($anahtar === 'rush') {
        return '<svg class="stickman-status-icon stickman-rush" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="1.8" y1="8.5" x2="5.8" y2="8.5"></line><line x1="1.2" y1="12.5" x2="4.8" y2="12.5"></line><circle cx="16.2" cy="5.2" r="2.2"></circle><line x1="15" y1="7.2" x2="10.8" y2="13.8"></line><polyline points="13.6 9.4 18.2 11.2 19.8 9.2"></polyline><polyline points="13.6 9.4 9.2 8.2 7.6 10.4"></polyline><polyline points="10.8 13.8 15.8 15.6 14.8 20.8"></polyline><polyline points="10.8 13.8 6.8 17.5 4.2 16.5"></polyline></svg>';
    }
    if ($anahtar === 'walk') {
        return '<svg class="stickman-status-icon stickman-walk" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12.5" cy="4.8" r="2.3"></circle><line x1="12.2" y1="7.2" x2="11.5" y2="14.2"></line><polyline points="12 9.2 16.2 11.8 17.8 10.4"></polyline><polyline points="12 9.2 8.2 12.2 6.8 13.8"></polyline><polyline points="11.5 14.2 15.2 18 16.5 21.2"></polyline><polyline points="11.5 14.2 8.4 18 7.2 21.2"></polyline></svg>';
    }
    if ($anahtar === 'done') {
        return '<svg class="stickman-status-icon stickman-done" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5.8" r="2.3"></circle><line x1="12" y1="9.8" x2="6.8" y2="4.2"></line><line x1="12" y1="9.8" x2="17.2" y2="4.2"></line><line x1="12" y1="8.2" x2="12" y2="15"></line><line x1="12" y1="15" x2="8.4" y2="21.2"></line><line x1="12" y1="15" x2="15.6" y2="21.2"></line></svg>';
    }
    return '<svg class="stickman-status-icon stickman-chill" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="13" cy="5.2" r="2.3"></circle><polyline points="10.8 4.8 7.6 6.6 11.8 9.2"></polyline><polyline points="15.2 5.2 18.2 7.2 12.6 9.4"></polyline><line x1="12.2" y1="7.6" x2="10.2" y2="14.4"></line><line x1="10.2" y1="14.4" x2="6.8" y2="21"></line><polyline points="10.2 14.4 14.8 17.4 13.2 21"></polyline></svg>';
}

function son_tarih_aciliyet_html($son_tarih, $tamamlandi_mi = false)
{
    $ham = ($son_tarih === null || is_bool($son_tarih)) ? '' : trim((string) $son_tarih);
    if ($ham === '' || $ham === '0' || strpos($ham, '0000-00-00') === 0) {
        return '<span class="date-muted">-</span>';
    }
    $aciliyet = gorev_aciliyet_bilgisi($ham, $tamamlandi_mi);
    $html = '<span class="deadline-inline" title="' . e($aciliyet['etiket']) . '">';
    if (!empty($aciliyet['pulse'])) {
        $html .= '<span class="urgency-pulse-dot" aria-hidden="true"></span>';
    }
    $html .= stickman_durum_ikonu($aciliyet['mod']);
    $html .= '<span class="date-muted">' . e(tarih_format($ham)) . '</span>';
    $html .= '</span>';
    return $html;
}

function kisa_hafta_etiketi($hafta_etiketi)
{
    $temiz = trim((string) $hafta_etiketi);
    if ($temiz === '') {
        return 'H1';
    }
    if (preg_match('/^Hafta\s*(\d+)$/iu', $temiz, $eslesme)) {
        return 'H' . (int) $eslesme[1];
    }
    return metin_kisalt($temiz, 10);
}

function yonlendir($url)
{
    header('Location: ' . $url);
    exit;
}

function dosya_boyutu_format($bayt)
{
    $bayt = (int) $bayt;
    if ($bayt < 1024) {
        return $bayt . ' B';
    }
    if ($bayt < 1048576) {
        return number_format($bayt / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bayt / 1048576, 2, ',', '.') . ' MB';
}

function birim_secenekleri()
{
    return array(
        'genel' => 'Genel',
        'yazilim' => 'Yazılım',
        'elektronik' => 'Elektronik',
        'mekanik' => 'Mekanik',
        'raporlama' => 'Raporlama',
        'aviyonik' => 'Aviyonik',
        'aerodinamik' => 'Aerodinamik',
    );
}

function birim_kodu_normalize($birim, $varsayilan = 'genel')
{
    if (!is_string($birim) && !is_numeric($birim)) {
        return $varsayilan;
    }
    $temiz = trim((string) $birim);
    if ($temiz === '') {
        return $varsayilan;
    }
    $anahtar = function_exists('mb_strtolower')
        ? mb_strtolower($temiz, 'UTF-8')
        : strtolower($temiz);
    if (in_array($anahtar, array('tasarim', 'tasarım', "tasari\xCC\x87m", "avi\xCC\x87yoni\xCC\x87k", "avi\xCC\x87yonik", "aviyoni\xCC\x87k"), true)) {
        return 'aviyonik';
    }
    if (in_array($anahtar, array('aerodinamik', 'aerodınamık', 'aerodınamik', 'aerodinamık', "aerodi\xCC\x87nami\xCC\x87k", "aerodi\xCC\x87namik", "aerodinami\xCC\x87k", 'aerodİnamİk', 'aerodİnamik', 'aerodinamİk'), true)) {
        return 'aerodinamik';
    }
    $secenekler = birim_secenekleri();
    return isset($secenekler[$anahtar]) ? $anahtar : $varsayilan;
}

function birim_filtre_degerleri($birim_kodu)
{
    $kod = birim_kodu_normalize($birim_kodu, '');
    if ($kod === '') {
        return array();
    }
    if ($kod === 'aviyonik') {
        return array('aviyonik', 'tasarim', 'Tasarim', 'tasarım', 'Tasarım', 'TASARIM', 'TASARİM', "tasari\xCC\x87m", "TASARI\xCC\x87M");
    }
    if ($kod === 'aerodinamik') {
        return array('aerodinamik', 'Aerodinamik', 'AERODİNAMİK', 'AERODINAMIK', 'aerodınamık', "aerodi\xCC\x87nami\xCC\x87k", "AERODI\xCC\x87NAMI\xCC\x87K");
    }
    return array($kod);
}

function birim_ikonu($birim)
{
    $anahtar = birim_kodu_normalize($birim, 'genel');
    if ($anahtar === 'aviyonik') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>';
    }
    if ($anahtar === 'aerodinamik') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2"></path><path d="M9.6 4.6A2 2 0 1 1 11 8H2"></path><path d="M12.6 19.4A2 2 0 1 0 14 16H2"></path></svg>';
    }
    if ($anahtar === 'yazilim') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline><line x1="14" y1="4" x2="10" y2="20"></line></svg>';
    }
    if ($anahtar === 'elektronik') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"></rect><rect x="9.5" y="9.5" width="5" height="5" rx="1"></rect><line x1="9" y1="2" x2="9" y2="6"></line><line x1="15" y1="2" x2="15" y2="6"></line><line x1="9" y1="18" x2="9" y2="22"></line><line x1="15" y1="18" x2="15" y2="22"></line><line x1="2" y1="9" x2="6" y2="9"></line><line x1="2" y1="15" x2="6" y2="15"></line><line x1="18" y1="9" x2="22" y2="9"></line><line x1="18" y1="15" x2="22" y2="15"></line></svg>';
    }
    if ($anahtar === 'mekanik') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"></path></svg>';
    }
    if ($anahtar === 'raporlama') {
        return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="13" y2="17"></line></svg>';
    }
    return '<svg class="unit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 12 12 17 22 12"></polyline><polyline points="2 17 12 22 22 17"></polyline></svg>';
}

function birim_etiketi($birim)
{
    $secenekler = birim_secenekleri();
    $anahtar = birim_kodu_normalize($birim, 'genel');
    return array(
        'kod' => $anahtar,
        'metin' => $secenekler[$anahtar],
        'sinif' => 'unit-' . $anahtar,
        'ikon' => birim_ikonu($anahtar),
    );
}

function birim_roket_etiketi_html($birim)
{
    $bilgi = birim_etiketi($birim);
    return '<span class="unit-tag ' . e($bilgi['sinif']) . '">' . $bilgi['ikon'] . '<span>' . e($bilgi['metin']) . '</span></span>';
}

function bos_durum_gorseli($tip = 'gorev')
{
    $tur = is_string($tip) ? trim($tip) : 'gorev';
    if ($tur === 'oda') {
        return '<svg class="empty-state-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="10" width="20" height="20" rx="4" stroke="#2563eb"></rect><rect x="36" y="10" width="20" height="20" rx="4" stroke="#64748b"></rect><rect x="8" y="36" width="20" height="18" rx="4" stroke="#64748b"></rect><rect x="36" y="36" width="20" height="18" rx="4" stroke="#2563eb" stroke-dasharray="3 3"></rect><line x1="46" y1="41" x2="46" y2="49" stroke="#2563eb"></line><line x1="42" y1="45" x2="50" y2="45" stroke="#2563eb"></line></svg>';
    }
    if ($tur === 'takvim') {
        return '<svg class="empty-state-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="10" y="14" width="44" height="40" rx="6" stroke="#64748b"></rect><line x1="10" y1="25" x2="54" y2="25" stroke="#64748b"></line><line x1="21" y1="9" x2="21" y2="17" stroke="#2563eb" stroke-width="2"></line><line x1="43" y1="9" x2="43" y2="17" stroke="#2563eb" stroke-width="2"></line><circle cx="24" cy="35" r="2" fill="#2563eb" stroke="none"></circle><circle cx="32" cy="35" r="2" fill="#94a3b8" stroke="none"></circle><circle cx="40" cy="35" r="2" fill="#94a3b8" stroke="none"></circle><path d="M26 44l4 4 8-8" stroke="#2563eb" stroke-width="2"></path></svg>';
    }
    if ($tur === 'yorum') {
        return '<svg class="empty-state-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16h40a4 4 0 0 1 4 4v22a4 4 0 0 1-4 4H26l-10 8v-8h-4a4 4 0 0 1-4-4V20a4 4 0 0 1 4-4z" stroke="#64748b"></path><line x1="22" y1="27" x2="42" y2="27" stroke="#2563eb" stroke-width="2"></line><line x1="22" y1="35" x2="34" y2="35" stroke="#94a3b8" stroke-width="2"></line></svg>';
    }
    if ($tur === 'aktivite') {
        return '<svg class="empty-state-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="32" r="22" stroke="#64748b" stroke-dasharray="4 3"></circle><polyline points="16 33 25 33 29 23 35 41 39 33 48 33" stroke="#2563eb" stroke-width="2"></polyline></svg>';
    }
    return '<svg class="empty-state-illustration" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="14" y="10" width="36" height="44" rx="5" stroke="#64748b"></rect><path d="M24 10h16v5a2 2 0 0 1-2 2H26a2 2 0 0 1-2-2v-5z" stroke="#2563eb"></path><path d="M22 28l3 3 6-6" stroke="#2563eb" stroke-width="2"></path><line x1="35" y1="28" x2="43" y2="28" stroke="#94a3b8"></line><circle cx="25" cy="40" r="2" stroke="#94a3b8"></circle><line x1="35" y1="40" x2="43" y2="40" stroke="#94a3b8"></line></svg>';
}

function birim_gecerli_mi($birim)
{
    if (!is_string($birim)) {
        return false;
    }
    return birim_kodu_normalize($birim, '') !== '';
}

function oda_kodu_uret(PDO $pdo, $on_ek = 'ODA')
{
    $temiz_on_ek = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $on_ek));
    if ($temiz_on_ek === '') {
        $temiz_on_ek = defined('ODA_KODU_ON_EK') ? ODA_KODU_ON_EK : 'ODA';
    }
    if (strlen($temiz_on_ek) > 6) {
        $temiz_on_ek = substr($temiz_on_ek, 0, 6);
    }

    // Karisabilen 0/O/1/I karakterleri haric 32 karakterlik alfabe (modulo sapmasi yok)
    $alfabe = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $kontrol = $pdo->prepare('SELECT id FROM projeler WHERE davet_kodu = ? LIMIT 1');

    for ($deneme = 0; $deneme < 30; $deneme++) {
        $baytlar = random_bytes(6);
        $parca = '';
        for ($i = 0; $i < 6; $i++) {
            $indeks = ord($baytlar[$i]) & 31;
            $parca .= $alfabe[$indeks];
        }
        $kod = $temiz_on_ek . '-' . $parca;
        $kontrol->execute(array($kod));
        if (!$kontrol->fetch()) {
            return $kod;
        }
    }

    // Cok dusuk olasilikli yedek
    return $temiz_on_ek . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function metin_kisalt($metin, $max_uzunluk = 255)
{
    $temiz = trim((string) $metin);
    if (function_exists('mb_substr')) {
        return mb_substr($temiz, 0, (int) $max_uzunluk, 'UTF-8');
    }
    return substr($temiz, 0, (int) $max_uzunluk);
}

function bildirim_ekle(PDO $pdo, $hedef_kullanici_id, $tur, $mesaj, $link = null)
{
    $hedef_id = (int) $hedef_kullanici_id;
    if ($hedef_id <= 0) {
        return false;
    }
    // Kullaniciya kendi yaptigi islem icin bildirim gitmez
    if (isset($_SESSION['kullanici_id']) && (int) $_SESSION['kullanici_id'] === $hedef_id) {
        return false;
    }

    $tur_temiz = metin_kisalt($tur !== '' ? $tur : 'genel', 40);
    $mesaj_temiz = metin_kisalt($mesaj, 255);
    if ($mesaj_temiz === '') {
        return false;
    }
    $link_temiz = ($link !== null && trim((string) $link) !== '') ? metin_kisalt($link, 255) : null;

    try {
        $stmt = $pdo->prepare('
            INSERT INTO bildirimler (kullanici_id, tur, mesaj, link, okundu, olusturma_tarihi)
            VALUES (?, ?, ?, ?, 0, ?)
        ');
        $ok = $stmt->execute(array($hedef_id, $tur_temiz, $mesaj_temiz, $link_temiz, istanbul_simdi()));
        if ($ok) {
            // Kullanici basina en guncel 100 bildirimi tut, eskilerini temizle
            $esik_stmt = $pdo->prepare('
                SELECT id FROM bildirimler
                WHERE kullanici_id = ?
                ORDER BY id DESC
                LIMIT 1 OFFSET 100
            ');
            $esik_stmt->execute(array($hedef_id));
            $esik_id = $esik_stmt->fetchColumn();
            if ($esik_id !== false) {
                $sil_stmt = $pdo->prepare('DELETE FROM bildirimler WHERE kullanici_id = ? AND id <= ?');
                $sil_stmt->execute(array($hedef_id, (int) $esik_id));
            }
        }
        return $ok;
    } catch (PDOException $e) {
        return false;
    }
}

function aktivite_kaydet(PDO $pdo, $proje_id, $kullanici_id, $mesaj)
{
    $pid = (int) $proje_id;
    if ($pid <= 0) {
        return false;
    }
    $kid = ($kullanici_id !== null && (int) $kullanici_id > 0) ? (int) $kullanici_id : null;
    $mesaj_temiz = metin_kisalt($mesaj, 255);
    if ($mesaj_temiz === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO aktiviteler (proje_id, kullanici_id, mesaj, olusturma_tarihi)
            VALUES (?, ?, ?, ?)
        ');
        $ok = $stmt->execute(array($pid, $kid, $mesaj_temiz, istanbul_simdi()));
        if ($ok) {
            // Oda basina en guncel 200 aktiviteyi tut, eskilerini otomatik temizle
            $esik_stmt = $pdo->prepare('
                SELECT id FROM aktiviteler
                WHERE proje_id = ?
                ORDER BY id DESC
                LIMIT 1 OFFSET 200
            ');
            $esik_stmt->execute(array($pid));
            $esik_id = $esik_stmt->fetchColumn();
            if ($esik_id !== false) {
                $sil_stmt = $pdo->prepare('DELETE FROM aktiviteler WHERE proje_id = ? AND id <= ?');
                $sil_stmt->execute(array($pid, (int) $esik_id));
            }
        }
        return $ok;
    } catch (PDOException $e) {
        return false;
    }
}

function gorev_ilerleme_hesapla($gorev, $alt_toplam = 0, $alt_tamamlanan = 0)
{
    $toplam = (int) $alt_toplam;
    $tamamlanan = (int) $alt_tamamlanan;
    if ($toplam > 0) {
        $oran = (int) round(($tamamlanan / $toplam) * 100);
        if ($oran < 0) {
            return 0;
        }
        if ($oran > 100) {
            return 100;
        }
        return $oran;
    }

    $durum = is_array($gorev) && isset($gorev['durum']) ? (string) $gorev['durum'] : (string) $gorev;
    if ($durum === 'tamamlandi') {
        return 100;
    }
    if ($durum === 'devam_ediyor') {
        return 50;
    }
    return 0;
}

function gorev_listesi_ortalama_ilerleme($gorevler)
{
    if (!is_array($gorevler) || empty($gorevler)) {
        return 0;
    }
    $toplam_yuzde = 0;
    $adet = 0;
    foreach ($gorevler as $g) {
        $alt_toplam = is_array($g) && isset($g['alt_toplam']) ? (int) $g['alt_toplam'] : 0;
        $alt_tamamlanan = is_array($g) && isset($g['alt_tamamlanan']) ? (int) $g['alt_tamamlanan'] : 0;
        $toplam_yuzde += gorev_ilerleme_hesapla($g, $alt_toplam, $alt_tamamlanan);
        $adet++;
    }
    if ($adet <= 0) {
        return 0;
    }
    $ortalama = (int) round($toplam_yuzde / $adet);
    if ($ortalama < 0) {
        return 0;
    }
    if ($ortalama > 100) {
        return 100;
    }
    return $ortalama;
}

function gorev_durumu_alt_gorevlerle_senkronla(PDO $pdo, $gorev_id, $mevcut_durum = null)
{
    $gid = (int) $gorev_id;
    if ($mevcut_durum === null) {
        $g_stmt = $pdo->prepare('SELECT durum FROM gorevler WHERE id = ? LIMIT 1');
        $g_stmt->execute(array($gid));
        $g_satir = $g_stmt->fetch();
        $mevcut_durum = $g_satir ? (string) $g_satir['durum'] : 'yapilacak';
    } else {
        $mevcut_durum = (string) $mevcut_durum;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS toplam,
               SUM(CASE WHEN tamamlandi = 1 THEN 1 ELSE 0 END) AS tamamlanan
        FROM alt_gorevler
        WHERE gorev_id = ?
    ');
    $stmt->execute(array($gid));
    $ozet = $stmt->fetch();
    $toplam = $ozet ? (int) $ozet['toplam'] : 0;
    $tamamlanan = ($ozet && $ozet['tamamlanan'] !== null) ? (int) $ozet['tamamlanan'] : 0;

    $yeni_durum = $mevcut_durum;
    if ($toplam > 0 && $tamamlanan === $toplam) {
        $yeni_durum = 'tamamlandi';
    } elseif ($toplam > 0 && $tamamlanan > 0 && $tamamlanan < $toplam) {
        $yeni_durum = 'devam_ediyor';
    } elseif ($toplam > 0 && $tamamlanan === 0 && $mevcut_durum === 'tamamlandi') {
        $yeni_durum = 'devam_ediyor';
    }

    if ($yeni_durum !== $mevcut_durum) {
        $guncelle = $pdo->prepare('UPDATE gorevler SET durum = ?, guncelleme_tarihi = ? WHERE id = ?');
        $guncelle->execute(array($yeni_durum, istanbul_simdi(), $gid));
    }

    $ilerleme = gorev_ilerleme_hesapla(array('durum' => $yeni_durum), $toplam, $tamamlanan);

    return array(
        'alt_toplam' => $toplam,
        'alt_tamamlanan' => $tamamlanan,
        'ilerleme' => $ilerleme,
        'gorev_durum' => $yeni_durum,
    );
}

function teslim_durumu_hesapla($son_tarih, $durum = 'yapilacak')
{
    if ($durum === 'tamamlandi' || empty($son_tarih)) {
        return array(
            'durum' => 'normal',
            'metin' => '',
            'kisa' => '',
            'gun_farki' => null,
            'sinif' => '',
        );
    }

    $tarih_str = substr(trim((string) $son_tarih), 0, 10);
    $hedef_ts = strtotime($tarih_str . ' 00:00:00');
    $bugun_ts = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($hedef_ts === false || $bugun_ts === false) {
        return array(
            'durum' => 'normal',
            'metin' => '',
            'kisa' => '',
            'gun_farki' => null,
            'sinif' => '',
        );
    }

    $fark = (int) round(($hedef_ts - $bugun_ts) / 86400);

    if ($fark < 0) {
        $gecen = abs($fark);
        return array(
            'durum' => 'gecikti',
            'metin' => $gecen . ' gün gecikti',
            'kisa' => 'Gecikti',
            'gun_farki' => $fark,
            'sinif' => 'deadline-overdue',
        );
    }
    if ($fark === 0) {
        return array(
            'durum' => 'bugun',
            'metin' => 'Bugün son gün',
            'kisa' => 'Bugün',
            'gun_farki' => 0,
            'sinif' => 'deadline-today',
        );
    }
    if ($fark === 1) {
        return array(
            'durum' => 'yarin',
            'metin' => 'Yarın',
            'kisa' => 'Yarın',
            'gun_farki' => 1,
            'sinif' => 'deadline-soon',
        );
    }
    if ($fark <= 7) {
        return array(
            'durum' => 'yaklasiyor',
            'metin' => $fark . ' gün kaldı',
            'kisa' => $fark . ' gün',
            'gun_farki' => $fark,
            'sinif' => 'deadline-soon',
        );
    }

    return array(
        'durum' => 'normal',
        'metin' => $fark . ' gün kaldı',
        'kisa' => $fark . ' gün',
        'gun_farki' => $fark,
        'sinif' => 'deadline-normal',
    );
}

function kullanici_odalari_getir(PDO $pdo, $kullanici)
{
    $kullanici_id = is_array($kullanici) && isset($kullanici['id'])
        ? (int) $kullanici['id']
        : (int) $kullanici;

    if ($kullanici_id <= 0) {
        return array();
    }

    $stmt = $pdo->prepare('
        SELECT p.id, p.ad, p.kod, p.davet_kodu, p.durum, p.yonetici_id,
               COALESCE(pu.yetki, \'uye\') AS yetki
        FROM projeler p
        LEFT JOIN proje_uyeleri pu ON pu.proje_id = p.id AND pu.kullanici_id = ?
        WHERE p.yonetici_id = ? OR pu.kullanici_id IS NOT NULL
        ORDER BY p.olusturma_tarihi DESC
        LIMIT 20
    ');
    $stmt->execute(array($kullanici_id, $kullanici_id));
    return $stmt->fetchAll();
}

function okunmamis_bildirim_sayisi(PDO $pdo, $kullanici_id)
{
    $kid = (int) $kullanici_id;
    if ($kid <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bildirimler WHERE kullanici_id = ? AND okundu = 0');
        $stmt->execute(array($kid));
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
