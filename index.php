<?php
require_once 'vendor/autoload.php';
require_once 'routes/api.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
