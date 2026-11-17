<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // Tabel Berita
    $pdo->exec("CREATE TABLE IF NOT EXISTS news (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Tabel Audit Log
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Tambah kolom period_month di attendance (opsional, buat filter laporan)
    // Di sini kita pakai work_date yang sudah ada.

    echo "✅ Tabel news & audit_logs berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}