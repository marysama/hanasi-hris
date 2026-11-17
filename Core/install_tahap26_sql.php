<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tabel Backup Logs (Untuk Reliver)
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        work_date DATE NOT NULL,
        area_name VARCHAR(100) NOT NULL,
        allowance_amount DECIMAL(15,2) NOT NULL DEFAULT 80000,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Tabel Notifications (Inbox)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "✅ Tabel Backup & Notifikasi berhasil dibuat.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}