<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Cek dan tambah total_backup
    $check1 = $pdo->query("SHOW COLUMNS FROM payrolls LIKE 'total_backup'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE payrolls ADD COLUMN total_backup DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_overtime");
        echo "✅ Kolom 'total_backup' ditambahkan ke tabel payrolls.<br>";
    }

    // 2. Cek dan tambah total_night_shift (opsional, buat jaga-jaga)
    $check2 = $pdo->query("SHOW COLUMNS FROM payrolls LIKE 'total_night_shift'")->fetch();
    if (!$check2) {
        $pdo->exec("ALTER TABLE payrolls ADD COLUMN total_night_shift DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_backup");
        echo "✅ Kolom 'total_night_shift' ditambahkan ke tabel payrolls.<br>";
    }

    echo "<br><b>Schema Tahap 81 Selesai.</b>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}