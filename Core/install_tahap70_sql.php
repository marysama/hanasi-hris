<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Cek dan tambahkan kolom total_backup di tabel payrolls
    $check = $pdo->query("SHOW COLUMNS FROM payrolls LIKE 'total_backup'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE payrolls ADD COLUMN total_backup DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_overtime");
        echo "✅ Kolom 'total_backup' berhasil ditambahkan ke tabel payrolls.<br>";
    } else {
        echo "✅ Kolom 'total_backup' sudah ada.<br>";
    }
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}