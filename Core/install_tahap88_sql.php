<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    // Tambah setting cutoff_date jika belum ada
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('attendance_cutoff_date', '2026-12-01')");
    echo "✅ Setting 'attendance_cutoff_date' siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}