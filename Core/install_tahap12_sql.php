<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tambah kolom di attendance_records
    $pdo->exec("ALTER TABLE attendance_records 
        ADD COLUMN check_in_lat DECIMAL(10,8) NULL,
        ADD COLUMN check_in_lng DECIMAL(11,8) NULL,
        ADD COLUMN check_in_photo VARCHAR(255) NULL,
        ADD COLUMN check_out_lat DECIMAL(10,8) NULL,
        ADD COLUMN check_out_lng DECIMAL(11,8) NULL,
        ADD COLUMN check_out_photo VARCHAR(255) NULL
    ");
    
    echo "✅ Kolom GPS & Foto berhasil ditambahkan.<br>";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "✅ Kolom GPS & Foto sudah siap.<br>";
    } else {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    }
}