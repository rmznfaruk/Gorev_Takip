<?php
/**
 * Uygulama geneli yapilandirma sabitleri.
 * Her grup/oda kendi benzersiz davet kodunu (projeler.davet_kodu) otomatik uretir.
 * InfinityFree veya canli sunucuda bu dosyadan kolayca duzenlenebilir.
 */

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set(APP_TIMEZONE);
}

if (!defined('ODA_KODU_ON_EK')) {
    $env_on_ek = getenv('ODA_KODU_ON_EK');
    define(
        'ODA_KODU_ON_EK',
        ($env_on_ek !== false && trim($env_on_ek) !== '')
            ? strtoupper(trim($env_on_ek))
            : 'ODA'
    );
}

if (!defined('MAX_DOSYA_BOYUTU')) {
    define('MAX_DOSYA_BOYUTU', 8 * 1024 * 1024); // 8 MB
}

if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
}
