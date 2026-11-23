<?php
require_once __DIR__ . '/includes/auth.php';

cikis_yap();

session_start();
flash_ekle('success', 'Oturumunuz kapatıldı.');
yonlendir('index.php');
