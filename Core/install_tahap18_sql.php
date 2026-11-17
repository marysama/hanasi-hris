<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tambah kolom GPS & Foto di absensi
    $pdo->exec("ALTER TABLE attendance_records 
        ADD COLUMN check_in_lat DECIMAL(10,8) NULL,
        ADD COLUMN check_in_lng DECIMAL(11,8) NULL,
        ADD COLUMN check_in_photo VARCHAR(255) NULL,
        ADD COLUMN check_out_lat DECIMAL(10,8) NULL,
        ADD COLUMN check_out_lng DECIMAL(11,8) NULL,
        ADD COLUMN check_out_photo VARCHAR(255) NULL
    ");

    // 2. Tabel Pengaturan (Settings)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Default Setting (Monas)
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
        ('office_lat', '-6.175392'), 
        ('office_lng', '106.827153'), 
        ('office_radius', '100')
    ");

    echo "✅ Schema GPS, Settings, dan Tasks berhasil disiapkan.<br>";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "✅ Schema GPS sudah siap.<br>";
    } else {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    }
}