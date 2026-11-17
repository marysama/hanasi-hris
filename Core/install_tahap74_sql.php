<?php
require_once __DIR__ . '/../Core/Database.php';
use App\Core\Database;

try {
    $pdo = Database::pdo();
    
    // 1. Tambah kolom is_night_shift_eligible di employees
    $check1 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'is_night_shift_eligible'")->fetch();
    if (!$check1) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN is_night_shift_eligible TINYINT(1) DEFAULT 0 AFTER is_shift_required");
    }

    // 2. Tambah kolom total_backup di payrolls (jika belum)
    $check2 = $pdo->query("SHOW COLUMNS FROM payrolls LIKE 'total_backup'")->fetch();
    if (!$check2) {
        $pdo->exec("ALTER TABLE payrolls ADD COLUMN total_backup DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_overtime");
    }

    // 3. Pastikan tabel backup_logs ada
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        work_date DATE NOT NULL,
        area_name VARCHAR(100) NOT NULL,
        allowance_amount DECIMAL(15,2) NOT NULL DEFAULT 80000,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "✅ Schema Tahap 74 siap.<br>";
} catch (\Exception $e) {
    echo "❌ Error SQL: " . $e->getMessage() . "<br>";
}