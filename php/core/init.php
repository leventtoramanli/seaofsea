<?php
// core/init.php - Ana başlatıcı
require_once __DIR__ . '/../config/database.php';

// Modül (plugin) yükleyici
$plugins = ['users', 'tasks']; // Yüklenmesini istediğin modüller
foreach ($plugins as $plugin) {
    require_once __DIR__ . "/../plugins/$plugin/{$plugin}Routes.php";
}
