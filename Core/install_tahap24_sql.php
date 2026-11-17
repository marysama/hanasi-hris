<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tabel Lembur (Overtime)
    $pdo->exec("CREATE TABLE IF NOT EXISTS overtime_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        work_date DATE NOT NULL,
        duration_hours DECIMAL(5,2) NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('menunggu', 'disetujui', 'ditolak') DEFAULT 'menunggu',
        approved_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Update Tabel Leave Requests (Tambah jenis pengajuan)
    $checkCol = $pdo->query("SHOW COLUMNS FROM leave_requests LIKE 'type'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE leave_requests 
            ADD COLUMN type ENUM('cuti', 'izin', 'sakit') DEFAULT 'cuti' AFTER employee_id,
            ADD COLUMN attachment_path VARCHAR(255) NULL AFTER reason
        ");
    }

    echo "✅ Tabel & kolom baru (Lembur, Jenis Izin) berhasil disiapkan.<br>";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "✅ Schema Tahap 24 sudah siap.<br>";
    } else {
        echo "❌ Error SQL: " . $e->getMessage() . "<br>";
    }
}