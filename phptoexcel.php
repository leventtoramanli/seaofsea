<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Dosya yolu
$excelFilePath = 'descriptions/worldcities.xlsx'; // dosya yolu

// Excel dosyasını yükle
$spreadsheet = IOFactory::load($excelFilePath);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

// Veritabanı bağlantısı
$pdo = new PDO('mysql:host=localhost;dbname=seaofsea_db;charset=utf8mb4', 'root', '');
$count = 35379;

// İlk satır başlık olduğu için atla
foreach (array_slice($rows, 35379) as $row) {
    list($city, $city_ascii, $lat, $lng, $country, $iso2, $iso3, $admin_name, $capital, $population) = $row;

    $stmt = $pdo->prepare("INSERT INTO cities 
        (city, city_ascii, lat, lng, country, iso2, iso3, admin_name, capital, population) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $city,
        $city_ascii,
        $lat,
        $lng,
        $country,
        $iso2,
        $iso3,
        $admin_name,
        $capital,
        $population
    ]);
    echo $count." ".$city." - ".$country."<br>";
    $count++;
}

echo "Import işlemi tamamlandı!";
