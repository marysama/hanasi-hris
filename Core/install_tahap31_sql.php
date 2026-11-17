<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tabel Hari Libur (Off / Tanggal Merah)
    $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Update tabel leave_requests: Tambah status 'menunggu' & 'disetujui' sudah ada, 
    // kita pastikan ada kolom 'reason' dan 'attachment_path' (sudah ada dari tahap 24).
    // Tambahkan pengaman jika kolom 'type' belum ada.
    $checkCol = $pdo->query("SHOW COLUMNS FROM leave_requests LIKE 'type'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN type ENUM('cuti', 'izin', 'sakit') DEFAULT 'cuti' AFTER employee_id");
    }

    echo "✅ Tabel holidays (Hari Libur) berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}