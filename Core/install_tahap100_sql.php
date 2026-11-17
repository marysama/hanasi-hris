<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    // Tambah setting default potongan sakit untuk mobile (tanpa area)
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('sick_deduction_percent_mobile', '50')");
    echo "✅ Setting 'sick_deduction_percent_mobile' siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}