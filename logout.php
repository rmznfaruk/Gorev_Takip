<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

cikis_yap($pdo);

session_start();
flash_ekle('success', 'Oturumunuz kapatıldı.');
yonlendir('index.php');
